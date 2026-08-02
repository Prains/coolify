<?php

use App\Console\Commands\Generate\Services;
use Illuminate\Console\OutputStyle;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

uses(TestCase::class);

$generatePosthogTemplate = function (string $methodName): array {
    $command = new Services;
    $command->setOutput(new OutputStyle(new ArrayInput([]), new NullOutput));
    $method = new ReflectionMethod($command, $methodName);
    $payload = $method->invoke($command, 'posthog.yaml');

    expect($payload)->toBeArray();

    return $payload;
};

it('publishes a PostHog one-click service template with representative services', function () use ($generatePosthogTemplate) {
    foreach (['processFile', 'processFileWithFqdn'] as $methodName) {
        $template = $generatePosthogTemplate($methodName);

        expect($template['name'] ?? null)->toBe('posthog');

        $encodedCompose = $template['compose'] ?? null;
        expect($encodedCompose)->toBeString();

        $generatedCompose = base64_decode($encodedCompose, strict: true);
        expect($generatedCompose)->toBeString();

        $compose = Yaml::parse($generatedCompose);
        expect($compose['services'] ?? null)->toBeArray();

        foreach (['db', 'clickhouse', 'web', 'capture', 'replay-capture', 'feature-flags'] as $service) {
            expect($compose['services'])->toHaveKey($service);
        }
    }
});

it('publishes absolute FQDN URLs and a Content-Encoding-compatible SeaweedFS image', function () use ($generatePosthogTemplate) {
    $sourceTemplate = file_get_contents(__DIR__.'/../../templates/compose/posthog.yaml');

    expect($sourceTemplate)
        ->toContain('# fqdn_url_scheme: https')
        ->toContain('image: chrislusf/seaweedfs:4.29');

    $catalogExpectations = [
        'processFileWithFqdn' => [
            'urls' => [
                'SITE_URL=https://${SERVICE_FQDN_POSTHOG}',
                'OBJECT_STORAGE_PUBLIC_ENDPOINT=https://${SERVICE_FQDN_POSTHOG}',
                'LIVESTREAM_HOST=https://${SERVICE_FQDN_POSTHOG}/livestream',
            ],
            'forbiddenUrls' => [
                'SITE_URL=${SERVICE_URL_POSTHOG}',
                'OBJECT_STORAGE_PUBLIC_ENDPOINT=${SERVICE_URL_POSTHOG}',
                'LIVESTREAM_HOST=${SERVICE_URL_POSTHOG}/livestream',
            ],
        ],
        'processFile' => [
            'urls' => [
                'SITE_URL=${SERVICE_URL_POSTHOG}',
                'OBJECT_STORAGE_PUBLIC_ENDPOINT=${SERVICE_URL_POSTHOG}',
                'LIVESTREAM_HOST=${SERVICE_URL_POSTHOG}/livestream',
            ],
            'forbiddenUrls' => [
                'SITE_URL=https://${SERVICE_FQDN_POSTHOG}',
                'OBJECT_STORAGE_PUBLIC_ENDPOINT=https://${SERVICE_FQDN_POSTHOG}',
                'LIVESTREAM_HOST=https://${SERVICE_FQDN_POSTHOG}/livestream',
            ],
        ],
    ];

    foreach ($catalogExpectations as $methodName => $expectations) {
        $template = $generatePosthogTemplate($methodName);
        $generatedCompose = base64_decode($template['compose'], strict: true);
        $compose = Yaml::parse($generatedCompose);

        expect($generatedCompose)->toContain(...$expectations['urls']);
        expect($generatedCompose)->not->toContain(...$expectations['forbiddenUrls']);
        expect($compose['services']['seaweedfs']['image'])->toBe('chrislusf/seaweedfs:4.29');
    }
});
