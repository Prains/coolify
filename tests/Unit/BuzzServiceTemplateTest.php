<?php

use App\Console\Commands\Generate\Services;
use Illuminate\Console\OutputStyle;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\TestCase;

uses(TestCase::class);

$generateServiceTemplate = function (string $file, string $methodName): array {
    $command = new Services;
    $command->setOutput(new OutputStyle(new ArrayInput([]), new NullOutput));
    $method = new ReflectionMethod($command, $methodName);
    $payload = $method->invoke($command, $file);

    expect($payload)->toBeArray();

    return $payload;
};

it('includes a production-ready Buzz one-click service template', function () use ($generateServiceTemplate) {
    $compose = file_get_contents(__DIR__.'/../../templates/compose/buzz.yaml');

    expect($compose)
        ->toContain('# fqdn_url_scheme: https')
        ->toContain('ghcr.io/block/buzz:${BUZZ_TAG:-main}')
        ->toContain('SERVICE_URL_BUZZ_3000')
        ->toContain('RELAY_URL=wss://${SERVICE_FQDN_BUZZ}')
        ->toContain('BUZZ_MEDIA_BASE_URL=${SERVICE_URL_BUZZ}/media')
        ->toContain('BUZZ_CORS_ORIGINS=${SERVICE_URL_BUZZ}')
        ->toContain('BUZZ_RELAY_PRIVATE_KEY=${SERVICE_HEX_64_RELAYKEY}')
        ->toContain('BUZZ_AUTO_MIGRATE=${BUZZ_AUTO_MIGRATE:-true}')
        ->toContain('minio-init')
        ->toContain('exclude_from_hc: true')
        ->toContain('postgres:17-alpine')
        ->toContain('redis:7-alpine');

    foreach (['processFileWithFqdn', 'processFile'] as $methodName) {
        $template = $generateServiceTemplate('buzz.yaml', $methodName);

        expect($template['name'] ?? null)->toBe('buzz');
        expect($template['port'] ?? null)->toBe('3000');
        expect($template['logo'] ?? null)->toBe('svgs/buzz.svg');
        expect($template['category'] ?? null)->toBe('messaging');

        $generatedCompose = base64_decode($template['compose'], strict: true);

        expect($generatedCompose)
            ->toContain('ghcr.io/block/buzz:${BUZZ_TAG:-main}')
            ->toContain('wss://');

        if ($methodName === 'processFileWithFqdn') {
            expect($generatedCompose)
                ->toContain('BUZZ_MEDIA_BASE_URL=https://${SERVICE_FQDN_BUZZ}/media')
                ->toContain('BUZZ_CORS_ORIGINS=https://${SERVICE_FQDN_BUZZ}');
        } else {
            expect($generatedCompose)
                ->toContain('BUZZ_MEDIA_BASE_URL=${SERVICE_URL_BUZZ}/media')
                ->toContain('BUZZ_CORS_ORIGINS=${SERVICE_URL_BUZZ}')
                ->not->toContain('BUZZ_MEDIA_BASE_URL=https://${SERVICE_FQDN_BUZZ}/media')
                ->not->toContain('BUZZ_CORS_ORIGINS=https://${SERVICE_FQDN_BUZZ}');
        }
    }

    $chaskiqTemplate = $generateServiceTemplate('chaskiq.yaml', 'processFileWithFqdn');
    $chaskiqCompose = base64_decode($chaskiqTemplate['compose'], strict: true);

    expect($chaskiqCompose)
        ->toContain('HOST=${SERVICE_FQDN_CHASKIQ_3000}')
        ->not->toContain('HOST=https://${SERVICE_FQDN_CHASKIQ_3000}');
});
