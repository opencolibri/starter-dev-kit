<?php

declare(strict_types=1);

namespace OpenColibri\Starter\DevKit;

use OpenColibri\Starter\Core\App;
use OpenColibri\Starter\Core\Service\SyliusService;
use OpenColibri\Starter\Core\Task\TaskProviderRegistry;
use OpenColibri\Starter\DevKit\Tasks\DevKitTasks;

TaskProviderRegistry::register(
    'dev-kit',
    static fn(SyliusService $service): iterable => (new DevKitTasks(new App($service->getName(), $service->getDirectory(), $service->getDomains()[0] ?? null)))(),
);
