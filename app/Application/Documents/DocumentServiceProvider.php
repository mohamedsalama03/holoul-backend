<?php

declare(strict_types=1);

namespace App\Application\Documents;

use App\Infrastructure\Async\OperationHandlerRegistry;
use App\Infrastructure\Operations\MetricRecorder;
use App\Modules\Documents\Actions\ExtractDocumentText;
use App\Modules\Documents\Actions\ManageDocuments;
use App\Modules\Documents\Adapters\ClamAvScanner;
use App\Modules\Documents\Adapters\IsolatedDocumentInspector;
use App\Modules\Documents\Adapters\IsolatedDocumentTextExtractor;
use App\Modules\Documents\Adapters\S3AdapterFactory;
use App\Modules\Documents\Console\ReconcileDocumentsCommand;
use App\Modules\Documents\Console\RetryDocumentDeletionsCommand;
use App\Modules\Documents\Contracts\DocumentInspector;
use App\Modules\Documents\Contracts\DocumentReferences;
use App\Modules\Documents\Contracts\DocumentService;
use App\Modules\Documents\Contracts\DocumentTextExtractor;
use App\Modules\Documents\Contracts\DocumentTextService;
use App\Modules\Documents\Contracts\MalwareScanner;
use App\Modules\Documents\Contracts\PrivateObjectStore;
use App\Modules\Documents\Processing\DocumentOperationHandler;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;

final class DocumentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(DocumentService::class, ManageDocuments::class);
        $this->app->bind(DocumentTextService::class, ExtractDocumentText::class);
        $this->app->bind(DocumentTextExtractor::class, fn (): DocumentTextExtractor => new IsolatedDocumentTextExtractor(Config::string('documents.inspector_socket')));
        $this->app->bind(DocumentReferences::class, RetainedDocumentReferences::class);
        $this->app->bind(PrivateObjectStore::class, fn (): PrivateObjectStore => new ObservedObjectStore(S3AdapterFactory::create(
            Config::string('documents.s3.endpoint'), Config::string('documents.s3.region'), Config::string('documents.s3.bucket'),
            Config::string('documents.s3.access_key'), Config::string('documents.s3.secret_key'), Config::string('documents.s3.ca_bundle')), $this->app->make(MetricRecorder::class)));
        $this->app->bind(MalwareScanner::class, fn (): MalwareScanner => new ClamAvScanner(Config::string('documents.scanner_socket'), Config::integer('documents.max_signature_age_seconds')));
        $this->app->bind(DocumentInspector::class, fn (): DocumentInspector => new IsolatedDocumentInspector(Config::string('documents.inspector_socket')));
    }

    public function boot(OperationHandlerRegistry $operations): void
    {
        foreach (['documents.scan', 'documents.delete', 'documents.delete_orphan'] as $kind) {
            $operations->register($kind, new DocumentOperationHandler($this->app, $kind));
        }
        $this->commands([ReconcileDocumentsCommand::class, RetryDocumentDeletionsCommand::class, ExpireIntakeUploadsCommand::class]);
    }
}
