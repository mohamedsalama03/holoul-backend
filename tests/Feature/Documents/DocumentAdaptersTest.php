<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Modules\Documents\Adapters\ClamAvScanner;
use App\Modules\Documents\Adapters\IsolatedDocumentInspector;
use App\Modules\Documents\Contracts\DocumentInspector;
use App\Modules\Documents\Contracts\DocumentTextExtractor;
use App\Modules\Documents\Contracts\MalwareScanner;
use App\Modules\Documents\Contracts\PrivateObjectStore;
use App\Modules\Documents\Data\DocumentFormat;
use App\Modules\Documents\Data\MalwareVerdict;
use App\Modules\Documents\Data\StoredObject;
use App\Modules\Documents\Exceptions\DocumentExtractionRejected;
use App\Modules\Documents\Exceptions\InspectionUnavailable;
use App\Modules\Documents\Exceptions\ScannerUnavailable;
use App\Modules\Documents\Exceptions\StorageConflict;
use App\Modules\Documents\Exceptions\StorageUnavailable;
use Illuminate\Support\Str;
use Tests\TestCase;
use ZipArchive;

/** Real private S3, real ClamAV signatures, and the isolated production inspector. No substitutes. */
final class DocumentAdaptersTest extends TestCase
{
    public function test_storage_keeps_an_exact_immutable_encrypted_version_and_retries_safely(): void
    {
        $store = $this->app->make(PrivateObjectStore::class);
        $key = 'adapter-tests/'.Str::uuid7();
        $bytes = self::pdf();
        $stream = self::stream($bytes);
        $object = null;
        try {
            $object = $store->putIfAbsent($key, $stream, strlen($bytes), hash('sha256', $bytes), 'application/pdf');
            self::assertNotSame('', $object->versionId);
            self::assertNotSame('null', $object->versionId);
            self::assertEquals($object, $store->statVersion($key, $object->versionId));
            self::assertEquals($object, $store->putIfAbsent($key, $stream, strlen($bytes), hash('sha256', $bytes), 'application/pdf'));
            $download = $store->openVerified($object);
            self::assertSame($bytes, stream_get_contents($download));
            fclose($download);
            $different = self::stream($bytes.'different');
            try {
                $store->putIfAbsent($key, $different, strlen($bytes) + 9, hash('sha256', $bytes.'different'), 'application/pdf');
                self::fail('A storage key was overwritten.');
            } catch (StorageConflict) {
                self::assertTrue(true);
            } finally {
                fclose($different);
            }
            try {
                $store->openVerified(new StoredObject($key, $object->versionId, $object->size, str_repeat('0', 64), $object->mime));
                self::fail('A checksum mismatch was released.');
            } catch (StorageUnavailable) {
                self::assertTrue(true);
            }
            $seen = false;
            $cursor = null;
            do {
                $page = $store->versions($cursor, 100);
                foreach ($page->objects as $version) {
                    $seen = $seen || $version->key === $key && $version->versionId === $object->versionId;
                }
                $cursor = $page->nextCursor;
            } while (! $seen && $cursor !== null);
            self::assertTrue($seen);
        } finally {
            fclose($stream);
            if ($object !== null) {
                $store->deleteVersion($key, $object->versionId);
                $store->deleteVersion($key, $object->versionId);
            }
        }
    }

    public function test_real_clamav_accepts_clean_document_and_detects_eicar(): void
    {
        $scanner = $this->app->make(MalwareScanner::class);
        $clean = self::pdf();
        $stream = self::stream($clean);
        try {
            self::assertSame(MalwareVerdict::Clean, $scanner->scan($stream, strlen($clean)));
        } finally {
            fclose($stream);
        }
        $eicar = base64_decode('WDVPIVAlQEFQWzRcUFpYNTQoUF4pN0NDKTd9JEVJQ0FSLVNUQU5EQVJELUFOVElWSVJVUy1URVNULUZJTEUhJEgrSCo=', true);
        self::assertIsString($eicar);
        $stream = self::stream($eicar);
        try {
            self::assertSame(MalwareVerdict::Infected, $scanner->scan($stream, strlen($eicar)));
        } finally {
            fclose($stream);
        }
    }

    public function test_real_isolated_parser_accepts_minimal_pdf_and_docx(): void
    {
        $inspector = $this->app->make(DocumentInspector::class);
        foreach ([DocumentFormat::Pdf, DocumentFormat::Docx] as $format) {
            $bytes = $format === DocumentFormat::Pdf ? self::pdf() : self::docx();
            $stream = self::stream($bytes);
            try {
                $verdict = $inspector->inspect($stream, strlen($bytes), $format);
                self::assertTrue($verdict->safe, $format->value.':'.($verdict->reasonCode ?? 'unavailable'));
            } finally {
                fclose($stream);
            }
        }
    }

    public function test_real_parser_rejects_pdf_actions_trailing_polyglot_and_external_docx_relationship(): void
    {
        $inspector = $this->app->make(DocumentInspector::class);
        $cases = [
            [DocumentFormat::Pdf, self::pdf(' /OpenAction << /S /JavaScript /JS (app.alert\(1\)) >>')],
            [DocumentFormat::Pdf, self::pdf().'PK'.str_repeat('X', 32)],
            [DocumentFormat::Docx, self::docx(true)],
        ];
        foreach ($cases as [$format, $bytes]) {
            $stream = self::stream($bytes);
            try {
                self::assertFalse($inspector->inspect($stream, strlen($bytes), $format)->safe);
            } finally {
                fclose($stream);
            }
        }
    }

    public function test_missing_scanner_fails_closed(): void
    {
        $stream = self::stream('safe');
        try {
            $this->expectException(ScannerUnavailable::class);
            (new ClamAvScanner('/tmp/nonexistent-holoul-scanner.sock'))->scan($stream, 4);
        } finally {
            fclose($stream);
        }
    }

    public function test_missing_inspector_fails_closed(): void
    {
        $stream = self::stream('safe');
        try {
            $this->expectException(InspectionUnavailable::class);
            (new IsolatedDocumentInspector('/tmp/nonexistent-holoul-inspector.sock'))->inspect($stream, 4, DocumentFormat::Pdf);
        } finally {
            fclose($stream);
        }
    }

    public function test_real_offline_extraction_returns_only_bounded_docx_body_text(): void
    {
        $bytes = self::docx();
        $stream = self::stream($bytes);
        try {
            self::assertSame('Clean fixture', app(DocumentTextExtractor::class)->extract($stream, strlen($bytes), DocumentFormat::Docx, 100));
        } finally {
            fclose($stream);
        }
    }

    public function test_real_offline_extraction_rejects_textless_pdf_and_external_docx(): void
    {
        foreach ([[self::pdf(), DocumentFormat::Pdf, 'no_text'], [self::docx(true), DocumentFormat::Docx, 'dangerous_content']] as [$bytes, $format, $reason]) {
            $stream = self::stream($bytes);
            try {
                app(DocumentTextExtractor::class)->extract($stream, strlen($bytes), $format, 100);
                self::fail('Unsafe document extraction succeeded.');
            } catch (DocumentExtractionRejected $failure) {
                self::assertSame($reason, $failure->reasonCode);
            } finally {
                fclose($stream);
            }
        }
    }

    private static function pdf(string $extra = ''): string
    {
        $objects = ['<< /Type /Catalog /Pages 2 0 R'.$extra.' >>', '<< /Type /Pages /Kids [3 0 R] /Count 1 >>', '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 100 100] /Resources << >> /Contents 4 0 R >>', "<< /Length 0 >>\nstream\n\nendstream"];
        $pdf = "%PDF-1.7\n";
        $offsets = [];
        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1)." 0 obj\n".$object."\nendobj\n";
        }
        $start = strlen($pdf);
        $pdf .= "xref\n0 5\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf."trailer\n<< /Size 5 /Root 1 0 R >>\nstartxref\n".$start."\n%%EOF\n";
    }

    private static function docx(bool $external = false): string
    {
        $path = tempnam(sys_get_temp_dir(), 'holoul-adapter-');
        self::assertIsString($path);
        try {
            $zip = new ZipArchive;
            self::assertTrue($zip->open($path, ZipArchive::OVERWRITE));
            $zip->addFromString('[Content_Types].xml', '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
            $zip->addFromString('_rels/.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="r1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>');
            $zip->addFromString('word/document.xml', '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>Clean fixture</w:t></w:r></w:p></w:body></w:document>');
            if ($external) {
                $zip->addFromString('word/_rels/document.xml.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="r2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/hyperlink" Target="https://example.test/" TargetMode="External"/></Relationships>');
            }
            self::assertTrue($zip->close());
            $bytes = file_get_contents($path);
            self::assertIsString($bytes);

            return $bytes;
        } finally {
            unlink($path);
        }
    }

    /** @return resource */
    private static function stream(string $bytes): mixed
    {
        $stream = tmpfile();
        self::assertIsResource($stream);
        self::assertSame(strlen($bytes), fwrite($stream, $bytes));
        rewind($stream);

        return $stream;
    }
}
