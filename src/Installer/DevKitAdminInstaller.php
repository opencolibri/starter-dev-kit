<?php

declare(strict_types=1);

namespace OpenColibri\Starter\DevKit\Installer;

use Castor\Docker\Installer\AbstractServiceInstaller;
use Castor\Docker\Installer\Ast\Ast;
use Castor\Docker\Installer\Ast\ServiceStatementBuilder;
use Castor\Docker\Installer\Input;
use Castor\Docker\Installer\InputType;
use Castor\Docker\Service\PhpMode;
use Castor\Docker\Service\ServiceInterface;
use Castor\Docker\Service\SymfonyService;

use function Castor\context;
use function Castor\Docker\docker_compose_run;
use function Castor\fs;

/**
 * "castor docker:service:install dev-kit": the dev-kit admin (opencolibri/starter-dev-kit-bundle) as a Symfony
 * service of the stack, in the dev-kit/ directory of the project, copied from resources/admin/.
 *
 * The service mounts the root of the project and runs in dev-kit/: the admin reads the progress and the datasets of
 * .castor/import/ next to it. It calls castor-api ("castor api:serve-development") on the host, through
 * host.docker.internal.
 */
final class DevKitAdminInstaller extends AbstractServiceInstaller
{
    public const string NAME = 'dev-kit';

    public const string DIRECTORY = 'dev-kit';

    /** castor-api, run on the host by "castor api:serve-development", seen from the container. */
    public const string CASTOR_API_URL = 'http://host.docker.internal:8080';

    public function getName(): string
    {
        return self::NAME;
    }

    public function getDescription(): string
    {
        return 'Dev-kit admin: build the local Sylius shop from the browser';
    }

    public function getInputs(): array
    {
        return [
            new Input('version', 'PHP version', InputType::Text, '8.5'),
            new Input('domain', 'Domain of the admin', InputType::Text, static fn(): string => \sprintf('dev-kit.%s', context()->data['root_domain'] ?? 'castor.local')),
            new Input('storefront_url', 'URL of the storefront', InputType::Text, 'https://app.test/'),
        ];
    }

    public function buildStatements(ServiceStatementBuilder $builder, array $answers): void
    {
        $expression = $builder->addNewServiceAst(SymfonyService::class, [self::NAME])
            ->callMethod('withDirectory', [Ast::raw('__DIR__')])
            ->callMethod('withWorkingDirectory', [self::DIRECTORY])
            ->callMethod('withVersion', [(string) $answers['version']])
            ->callMethod('withMode', [Ast::raw('PhpMode::' . PhpMode::FrankenPhp->name)])
        ;
        $builder->addImport(PhpMode::class);

        if ('' !== ($answers['domain'] ?? '')) {
            $expression->callMethod('withDomain', [(string) $answers['domain']]);
        }
    }

    public function createInstance(array $answers): ServiceInterface
    {
        $service = (new SymfonyService(self::NAME))
            ->withDirectory(context()->workingDirectory)
            ->withWorkingDirectory(self::DIRECTORY)
            ->withVersion((string) $answers['version'])
            ->withMode(PhpMode::FrankenPhp)
        ;

        if ('' !== ($answers['domain'] ?? '')) {
            $service->withDomain((string) $answers['domain']);
        }

        return $service;
    }

    public function prepare(array $answers): void
    {
        $target = context()->workingDirectory . '/' . self::DIRECTORY;

        fs()->mirror(self::template(), $target);

        // Specific to this stack, so not in the template: the secret, the URLs of the admin and of the storefront.
        $environment = ['APP_SECRET' => bin2hex(random_bytes(16)), ...self::environment($answers)];
        fs()->dumpFile($target . '/.env.local', implode('', array_map(
            static fn(string $key, string $value): string => \sprintf("%s=%s\n", $key, $value),
            array_keys($environment),
            $environment,
        )));
    }

    public function scaffold(array $answers): void
    {
        $workDir = '/var/www/' . self::DIRECTORY;

        foreach (['composer install --no-interaction', 'php bin/console importmap:install', 'php bin/console tailwind:build'] as $command) {
            docker_compose_run($command, service: self::NAME . '-builder', workDir: $workDir);
        }
    }

    public static function template(): string
    {
        return \dirname(__DIR__, 2) . '/resources/admin';
    }

    /**
     * @param array<string, mixed> $answers
     *
     * @return array<string, string>
     */
    private static function environment(array $answers): array
    {
        $environment = [
            'CASTOR_API_URL' => self::CASTOR_API_URL,
            'DEV_KIT_STOREFRONT_URL' => (string) $answers['storefront_url'],
        ];

        if ('' !== ($answers['domain'] ?? '')) {
            $environment['DEFAULT_URI'] = \sprintf('https://%s', $answers['domain']);
        }

        return $environment;
    }
}
