<?php

declare(strict_types=1);

namespace OpenColibri\Starter\DevKit;

use Castor\Attribute\AsListener;
use Castor\Docker\Event\RegisterServiceInstallerEvent;
use OpenColibri\Starter\Core\App;
use OpenColibri\Starter\Core\Service\SyliusService;
use OpenColibri\Starter\Core\Task\TaskProviderRegistry;
use OpenColibri\Starter\DevKit\Installer\DevKitAdminInstaller;
use OpenColibri\Starter\DevKit\Tasks\DevKitTasks;

TaskProviderRegistry::register(
    'dev-kit',
    static fn(SyliusService $service): iterable => (new DevKitTasks(new App($service->getName(), $service->getDirectory(), $service->getDomains()[0] ?? null)))(),
);

/** "castor docker:service:install dev-kit": the dev-kit admin, run by "castor dev-kit:init". */
#[AsListener(RegisterServiceInstallerEvent::class)]
function register_dev_kit_installer(RegisterServiceInstallerEvent $event): void
{
    $event->addInstaller(new DevKitAdminInstaller());
}
