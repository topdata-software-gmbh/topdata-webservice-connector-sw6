<?php

declare(strict_types=1);

namespace Topdata\TopdataConnectorSW6\ScheduledTask;

use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;
use Symfony\Component\DependencyInjection\ParameterBag\ContainerBagInterface;

class ConnectorImportTaskHandler extends ScheduledTaskHandler
{
    /**
     * @var string
     */
    protected $projectPath;

    public function __construct(EntityRepository $scheduledTaskRepository, ContainerBagInterface $ContainerBag)
    {
        $this->projectPath = $ContainerBag->get('kernel.project_dir');
        parent::__construct($scheduledTaskRepository);
    }

    public static function getHandledMessages(): iterable
    {
        return [ConnectorImportTask::class];
    }

    public function run(): void
    {
        /* @TODO:
         * uncomment???
         */
        //        exec("php " . $this->projectPath . '/bin/console topdata:connector:import --all --no-debug > /dev/null');
    }
}
