<?php

/**
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; under version 2
 * of the License (non-upgradable).
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
 *
 * Copyright (c) 2021 (original work) Open Assessment Technologies SA;
 */

declare(strict_types=1);

namespace oat\taoDacSimple\test\unit\model\eventHandler;

use core_kernel_classes_Class;
use core_kernel_classes_Resource;
use oat\generis\model\data\Ontology;
use oat\generis\test\MockObject;
use oat\generis\test\ServiceManagerMockTrait;
use oat\taoDacSimple\model\ChangePermissionsService;
use oat\taoDacSimple\model\Command\ChangePermissionsCommand;
use PHPUnit\Framework\TestCase;
use oat\tao\model\event\ResourceMovedEvent;
use oat\taoDacSimple\model\eventHandler\ResourceUpdateHandler;
use oat\taoDacSimple\model\RolePrivilegeRetriever;

class ResourceUpdateHandlerTest extends TestCase
{
    use ServiceManagerMockTrait;

    /** @var RolePrivilegeRetriever|MockObject */
    private $rolePrivilegeRetriever;

    /** @var ResourceMovedEvent|MockObject */
    private $eventMock;

    /** @var core_kernel_classes_Resource|MockObject */
    private $resourceMock;

    /** @var core_kernel_classes_Class|MockObject */
    private $classMock;

    /** @var ResourceUpdateHandler */
    private $subject;

    /**
     * @var ChangePermissionsService|MockObject
     */
    private $changePermissionsServiceMock;

    public function setUp(): void
    {
        $this->rolePrivilegeRetriever = $this->createMock(RolePrivilegeRetriever::class);
        $this->resourceMock = $this->createMock(core_kernel_classes_Resource::class);
        $this->ontoloigyModelMock = $this->createMock(Ontology::class);

        $this->classMock = $this->createMock(core_kernel_classes_Class::class);
        $this->classMock
            ->method('getUri')
            ->willReturn('destinationClassUri');

        $this->eventMock = $this->createMock(ResourceMovedEvent::class);
        $this->eventMock
            ->method('getDestinationClass')
            ->willReturn($this->classMock);

        $this->changePermissionsServiceMock = $this->createMock(ChangePermissionsService::class);

        $this->subject = new ResourceUpdateHandler();
        $this->subject->setModel($this->ontoloigyModelMock);
        $this->subject->setServiceLocator(
            $this->getServiceManagerMock(
                [
                    RolePrivilegeRetriever::class => $this->rolePrivilegeRetriever,
                    ChangePermissionsService::class => $this->changePermissionsServiceMock,
                ]
            )
        );
    }

    public function testCatchResourceUpdated()
    {
        $resourceMock = $this->createMock(core_kernel_classes_Resource::class);

        $this->eventMock
            ->method('getMovedResource')
            ->willReturn($resourceMock);

        $resourceMock
            ->method('getUri')
            ->willReturn('movedClassResourceUri');

        $this->rolePrivilegeRetriever
            ->expects($this->once())
            ->method('retrieveByResourceIds')
            ->with(
                [
                    'destinationClassUri',
                    'movedClassResourceUri'
                ]
            )
            ->willReturn(
                [
                    'http://www.tao.lu/Ontologies/TAO.rdf#BackOfficeRole' => [
                        'GRANT',
                        'READ',
                        'WRITE'
                    ],
                ]
            );

        $this->changePermissionsServiceMock
            ->expects($this->once())
            ->method('change')
            ->with(
                new ChangePermissionsCommand(
                    $this->eventMock->getMovedResource(),
                    [
                        'http://www.tao.lu/Ontologies/TAO.rdf#BackOfficeRole' => [
                            'GRANT',
                            'READ',
                            'WRITE'
                        ],
                    ],
                    true
                )
            );

        $this->subject->catchResourceUpdated($this->eventMock);
    }

    public function testCatchClassResourceUpdated()
    {
        $classMock = $this->createMock(core_kernel_classes_Class::class);
        $classResourceMock = $this->createMock(core_kernel_classes_Resource::class);

        $this->ontoloigyModelMock
            ->expects(self::exactly(2))
            ->method('getResource')
            ->willReturn($this->resourceMock);

        $classResourceMock
            ->expects(self::exactly(2))
            ->method('getUri')
            ->willReturnOnConsecutiveCalls(
                'ClassResourceUri_1',
                'ClassResourceUri_2',
            );

        $classMock
            ->expects(self::once())
            ->method('getUri')
            ->willReturn('movedClassUri');

        $classMock->expects(self::once())
            ->method('getInstances')
            ->willReturn([
                $classResourceMock,
                $classResourceMock
            ]);

        $classMock
            ->expects(self::once())
            ->method('isClass')
            ->willReturn(true);

        $this->eventMock
            ->method('getMovedResource')
            ->willReturn($classMock);

        $this->rolePrivilegeRetriever
            ->expects($this->exactly(3))
            ->method('retrieveByResourceIds')
            ->withConsecutive(
                [
                    [
                        'destinationClassUri',
                        'movedClassUri'
                    ]
                ],
                [
                    [
                        'ClassResourceUri_1'
                    ]
                ],
                [
                    [
                        'ClassResourceUri_2'
                    ]
                ]
            )
            ->willReturnOnConsecutiveCalls(
                [
                    'http://www.tao.lu/Ontologies/TAO.rdf#BackOfficeRole' => [
                        'GRANT',
                        'READ',
                        'WRITE'
                    ],
                ],
                [
                    'http://www.tao.lu/Ontologies/TAO.rdf#Resource_1_Role' => [
                        'GRANT',
                        'READ',
                        'WRITE'
                    ],
                ],
                [
                    'http://www.tao.lu/Ontologies/TAO.rdf#Resource_2_Role' => [
                        'GRANT',
                        'READ',
                        'WRITE'
                    ],
                ],
            );

        $this->subject->catchResourceUpdated($this->eventMock);
    }
}
