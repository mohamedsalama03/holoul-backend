<?php

declare(strict_types=1);

namespace App\Application\PublicServices;

use App\Modules\PublicPortfolio\Data\LegacyImage;
use App\Modules\PublicPortfolio\Data\LegacyProject;
use App\Modules\PublicPortfolio\PortfolioPolicy;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Normalizer;

final class LegacyPortfolioReader
{
    /** @return list<LegacyProject> */
    public function read(string $manifest): array
    {
        $path = realpath($manifest);
        if ($path === false || ! is_file($path) || filesize($path) > 5242880) {
            throw new InvalidArgumentException('Invalid manifest.');
        }
        $root = json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        if (! is_array($root) || ($root['format'] ?? null) !== 1 || ! is_array($root['projects'] ?? null) || ! array_is_list($root['projects'])
            || count($root['projects']) > 1000 || ! is_array($root['images'] ?? null)) {
            throw new InvalidArgumentException('Invalid bundle format.');
        }
        $files = [];
        foreach ($root['images'] as $image) {
            if (! is_array($image) || ! is_string($image['id'] ?? null) || ! Str::isUuid($image['id'], 4) || isset($files[$image['id']])
                || ! is_string($image['sha256'] ?? null) || preg_match('/\A[0-9a-f]{64}\z/D', $image['sha256']) !== 1
                || ! is_int($image['byte_size'] ?? null) || $image['byte_size'] < 1 || $image['byte_size'] > 5242880) {
                throw new InvalidArgumentException('Invalid image manifest.');
            }
            $file = dirname($path).'/images/'.$image['id'].'.webp';
            if (is_link($file) || realpath(dirname($file)) !== dirname($path).'/images' || ! is_file($file)
                || filesize($file) !== $image['byte_size'] || hash_file('sha256', $file) !== $image['sha256']) {
                throw new InvalidArgumentException('Image bytes or path differ from manifest.');
            }
            $files[$image['id']] = ['path' => $file, 'sha256' => $image['sha256'], 'size' => $image['byte_size']];
        }
        $projects = [];
        $seen = [];
        $used = [];
        foreach ($root['projects'] as $project) {
            if (! is_array($project) || ! is_string($project['id'] ?? null) || ! Str::isUuid($project['id'], 4) || isset($seen[$project['id']])
                || ! is_string($project['category'] ?? null) || ! isset(PortfolioPolicy::CATEGORIES[$project['category']])
                || ! in_array($project['status'] ?? null, ['draft', 'published'], true) || ! is_array($project['images'] ?? null)
                || ! array_is_list($project['images']) || count($project['images']) > 8) {
                throw new InvalidArgumentException('Invalid project manifest.');
            }
            $seen[$project['id']] = true;
            $images = [];
            foreach ($project['images'] as $order => $image) {
                if (! is_array($image) || ! is_string($image['id'] ?? null) || ! isset($files[$image['id']]) || isset($used[$image['id']])) {
                    throw new InvalidArgumentException('Missing or duplicate image mapping.');
                }
                $used[$image['id']] = true;
                $file = $files[$image['id']];
                $images[] = new LegacyImage($image['id'], $file['path'], $file['sha256'], $file['size'], $this->text($image['alt'] ?? null, 1, 240), $order);
            }
            $hash = hash('sha256', json_encode([$project, array_map(static fn (LegacyImage $i): array => [$i->id, $i->sha256, $i->size], $images)], JSON_THROW_ON_ERROR));
            $projects[] = new LegacyProject($project['id'], $hash, $this->text($project['title'] ?? null, 2, 120), $this->text($project['summary'] ?? null, 10, 240),
                $this->text($project['description'] ?? null, 30, 12000, true), $project['category'], $project['status'], $images);
        }
        if (count($used) !== count($files)) {
            throw new InvalidArgumentException('Unreferenced image in bundle.');
        }

        return $projects;
    }

    private function text(mixed $value, int $min, int $max, bool $multiline = false): string
    {
        if (! is_string($value) || ! mb_check_encoding($value, 'UTF-8')) {
            throw new InvalidArgumentException('Invalid text.');
        }
        $normalized = Normalizer::normalize(str_replace(["\r\n", "\r"], "\n", trim($value)), Normalizer::FORM_C);
        if (! is_string($normalized) || mb_strlen($normalized) < $min || mb_strlen($normalized) > $max
            || preg_match($multiline ? '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u' : '/[\x00-\x1F\x7F]/u', $normalized) === 1
            || strip_tags($normalized) !== $normalized) {
            throw new InvalidArgumentException('Text requires editorial review before import.');
        }

        return $normalized;
    }
}
