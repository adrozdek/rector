<?php declare(strict_types=1);

namespace Rector\Doctrine\Extension;

use Nette\Utils\FileSystem;
use Nette\Utils\Json;
use Rector\Contract\Extension\FinishingExtensionInterface;
use Rector\Doctrine\Collector\UuidMigrationDataCollector;
use Symfony\Component\Console\Style\SymfonyStyle;

final class ReportEntitiesWithAddedPropertiesFinishExtension implements FinishingExtensionInterface
{
    /**
     * @var UuidMigrationDataCollector
     */
    private $uuidMigrationDataCollector;

    /**
     * @var SymfonyStyle
     */
    private $symfonyStyle;

    public function __construct(
        UuidMigrationDataCollector $uuidMigrationDataCollector,
        SymfonyStyle $symfonyStyle
    ) {
        $this->uuidMigrationDataCollector = $uuidMigrationDataCollector;
        $this->symfonyStyle = $symfonyStyle;
    }

    public function run(): void
    {
        $propertiesByClass = $this->uuidMigrationDataCollector->getPropertiesByClass();
        if ($propertiesByClass === []) {
            return;
        }

        $data = [
            'title' => 'Entities with new properties',
            'added_properties_by_class' => $propertiesByClass,
        ];

        $jsonContent = Json::encode($data, Json::PRETTY);

        $filePath = getcwd() . '/uuid-migration.json';
        FileSystem::write($filePath, $jsonContent);

        $this->symfonyStyle->warning(
            'See freshly created "uuid-migration.json" file for changes on entities and further SQL migration steps'
        );
    }
}
