<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Sidebar;

use App\Entity\Note;
use App\Service\Sidebar\SidebarBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SidebarBuilderTest extends KernelTestCase
{
    public function testBuildsTreeGroupedByFolderAndExcludesReports(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $builder = $container->get(SidebarBuilder::class);

        $em->createQuery('DELETE FROM App\Entity\Note')->execute();

        $people = new Note();
        $people->setVaultPath('People/Malekith.md');
        $people->setSlug('people/malekith');
        $people->setTitle('Malekith');
        $people->setTopLevelFolder('People');
        $people->setHtml('<p></p>');
        $em->persist($people);

        $nestedLocation = new Note();
        $nestedLocation->setVaultPath('Locations/Settlements/Silverymoon.md');
        $nestedLocation->setSlug('locations/settlements/silverymoon');
        $nestedLocation->setTitle('Silverymoon');
        $nestedLocation->setTopLevelFolder('Locations');
        $nestedLocation->setHtml('<p></p>');
        $em->persist($nestedLocation);

        $report = new Note();
        $report->setVaultPath('Reports/1-10/Report-1 x.md');
        $report->setSlug('reports/1-10/report-1-x');
        $report->setTitle('Report 1');
        $report->setTopLevelFolder('Reports');
        $report->setHtml('<p></p>');
        $report->setReportNumber(1);
        $em->persist($report);

        $em->flush();

        $root = $builder->build();
        $folderNames = array_map(fn ($f) => $f->name, $root->getFolders());

        self::assertContains('People', $folderNames);
        self::assertContains('Locations', $folderNames);
        self::assertNotContains('Reports', $folderNames);

        $locations = $root->getFolders()['Locations'];
        $settlements = $locations->getFolders()['Settlements'];
        self::assertSame('Silverymoon', $settlements->getNotes()[0]->getTitle());

        $em->createQuery('DELETE FROM App\Entity\Note')->execute();
    }

    public function testIncludeHiddenControlsWhetherHiddenNotesAppear(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $builder = $container->get(SidebarBuilder::class);

        $em->createQuery('DELETE FROM App\Entity\Note')->execute();

        $hidden = new Note();
        $hidden->setVaultPath('A - GM/Secrets.md');
        $hidden->setSlug('a-gm/secrets');
        $hidden->setTitle('Secrets');
        $hidden->setTopLevelFolder('A - GM');
        $hidden->setHtml('<p></p>');
        $hidden->setHidden(true);
        $em->persist($hidden);
        $em->flush();

        $rootWithoutHidden = $builder->build(false);
        self::assertArrayNotHasKey('A - GM', $rootWithoutHidden->getFolders());

        $rootWithHidden = $builder->build(true);
        self::assertArrayHasKey('A - GM', $rootWithHidden->getFolders());

        $em->createQuery('DELETE FROM App\Entity\Note')->execute();
    }
}
