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
 * Copyright (c) 2014 (original work) Open Assessment Technologies SA (under the project TAO-PRODUCT);
 *
 *
 */

namespace oat\taoDacSimple\model;

use common_persistence_SqlPersistence;
use oat\oatbox\event\EventManager;
use oat\oatbox\service\ConfigurableService;
use oat\taoDacSimple\model\event\DacAddedEvent;
use oat\taoDacSimple\model\event\DacRemovedEvent;
use oat\generis\persistence\PersistenceManager;
use PDOStatement;
use PDO;

/**
 * Class to handle the storage and retrieval of permissions
 *
 * @author Antoine Robin <antoine.robin@vesperiagroup.com>
 * @author Joel Bout <joel@taotesting.com>
 */
class DataBaseAccess extends ConfigurableService
{

    const SERVICE_ID = 'taoDacSimple/DataBaseAccess';

    const OPTION_PERSISTENCE = 'persistence';

    const COLUMN_USER_ID = 'user_id';
    const COLUMN_RESOURCE_ID = 'resource_id';
    const COLUMN_PRIVILEGE = 'privilege';
    const TABLE_PRIVILEGES_NAME = 'data_privileges';

    private $persistence;


    /**
     * @return EventManager
     */
    protected function getEventManager()
    {
        return $this->getServiceLocator()->get(EventManager::SERVICE_ID);
    }

    /**
     * We can know which users have a privilege on a resource
     * @param array $resourceIds
     * @return array list of users
     */
    public function getUsersWithPermissions($resourceIds)
    {
        $inQuery = implode(',', array_fill(0, count($resourceIds), '?'));
        $query = 'SELECT ' . self::COLUMN_RESOURCE_ID . ', ' . self::COLUMN_USER_ID . ', ' . self::COLUMN_PRIVILEGE
            . ' FROM ' . self::TABLE_PRIVILEGES_NAME
            . ' WHERE ' . self::COLUMN_RESOURCE_ID . ' IN (' . $inQuery . ')';
        return $this->fetchQuery($query, $resourceIds);
    }

    /**
     * Get the permissions for a list of resources and users
     *
     * @access public
     * @param array $userIds
     * @param array $resourceIds
     * @return array
     */
    public function getPermissions($userIds, array $resourceIds)
    {
        // get privileges for a user/roles and a resource
        $inQueryResource = implode(',', array_fill(0, count($resourceIds), '?'));
        $inQueryUser = implode(',', array_fill(0, count($userIds), '?'));
        $query = 'SELECT ' . self::COLUMN_RESOURCE_ID . ', ' . self::COLUMN_PRIVILEGE
            . ' FROM ' . self::TABLE_PRIVILEGES_NAME
            . ' WHERE ' . self::COLUMN_RESOURCE_ID . ' IN (' . $inQueryResource . ') AND '
            . self::COLUMN_USER_ID . ' IN (' . $inQueryUser . ')';

        $params = array_merge(array_values($resourceIds), array_values($userIds));

        //If resource doesn't have permission don't return null
        $returnValue = array_fill_keys($resourceIds, []);

        $results = $this->fetchQuery($query, $params);
        foreach ($results as $result) {
            $returnValue[$result[self::COLUMN_RESOURCE_ID]][] = $result[self::COLUMN_PRIVILEGE];
        }
        return $returnValue;
    }

    public function getResourcesPermissions(array $resourceIds)
    {
        //If resource doesn't have permission don't return null
        $returnValue = array_fill_keys($resourceIds, []);
        $results = $this->getUsersWithPermissions($resourceIds);
        foreach ($results as $result) {
            $returnValue[$result[self::COLUMN_RESOURCE_ID]][$result[self::COLUMN_USER_ID]][] = $result[self::COLUMN_PRIVILEGE];
        }
        return $returnValue;
    }

    /**
     * add permissions of a user to a resource
     *
     * @access public
     * @param string $user
     * @param string $resourceId
     * @param array $rights
     *
     * @return bool
     */
    public function addPermissions($user, $resourceId, $rights)
    {
        foreach ($rights as $privilege) {
            // add a line with user URI, resource Id and privilege
            $this->getPersistence()->insert(
                self::TABLE_PRIVILEGES_NAME,
                [self::COLUMN_USER_ID => $user, self::COLUMN_RESOURCE_ID => $resourceId, self::COLUMN_PRIVILEGE => $privilege]
            );
        }
        $this->getEventManager()->trigger(new DacAddedEvent($user, $resourceId, (array)$rights));
        return true;
    }


    /**
     * Get the permissions to resource
     *
     * @access public
     * @param string $resourceId
     * @return array
     */
    public function getResourcePermissions($resourceId)
    {
        // get privileges for a user/roles and a resource
        $query = 'SELECT ' . self::COLUMN_USER_ID . ', ' . self::COLUMN_PRIVILEGE
            . ' FROM ' . self::TABLE_PRIVILEGES_NAME
            . ' WHERE ' . self::COLUMN_RESOURCE_ID . ' = ?';

        $results = $this->fetchQuery($query, [$resourceId]);
        foreach ($results as $result) {
            $returnValue[$result[self::COLUMN_USER_ID]][] = $result[self::COLUMN_PRIVILEGE];
        }
        return $returnValue ?? [];
    }

    /**
     * remove permissions to a resource for a user
     *
     * @access public
     * @param string $user
     * @param string $resourceId
     * @param array $rights
     * @return boolean
     */
    public function removePermissions($user, $resourceId, $rights)
    {
        //get all entries that match (user,resourceId) and remove them
        $inQueryPrivilege = implode(',', array_fill(0, count($rights), ' ? '));
        $query = 'DELETE FROM ' . self::TABLE_PRIVILEGES_NAME . ' WHERE ' . self::COLUMN_RESOURCE_ID . ' = ? AND '
            . self::COLUMN_PRIVILEGE . ' IN (' . $inQueryPrivilege . ') AND ' . self::COLUMN_USER_ID . ' = ?';
        $params = array_merge([$resourceId], array_values($rights), [$user]);
        $this->getPersistence()->exec($query, $params);
        $this->getEventManager()->trigger(new DacRemovedEvent($user, $resourceId, $rights));

        return true;
    }

    /**
     * Remove all permissions from a resource
     *
     * @access public
     * @param array $resourceIds
     * @return boolean
     */
    public function removeAllPermissions($resourceIds)
    {
        //get all entries that match (resourceId) and remove them
        $inQuery = implode(',', array_fill(0, count($resourceIds), ' ? '));
        $query = 'DELETE FROM ' . self::TABLE_PRIVILEGES_NAME
            . ' WHERE ' . self::COLUMN_RESOURCE_ID . ' IN (' . $inQuery . ')';

        $this->getPersistence()->exec($query, $resourceIds);
        foreach ($resourceIds as $resourceId) {
            $this->getEventManager()->trigger(new DacRemovedEvent('-', $resourceId, '-'));
        }
        return true;
    }

    /**
     * Filter users\roles that have no permissions
     *
     * @access public
     * @param array $userIds
     * @return array
     */
    public function checkPermissions($userIds)
    {
        $inQueryUser = implode(',', array_fill(0, count($userIds), ' ? '));
        $query = 'SELECT ' . self::COLUMN_USER_ID . ' FROM ' . self::TABLE_PRIVILEGES_NAME
            . ' WHERE ' . self::COLUMN_USER_ID . ' IN (' . $inQueryUser . ')';
        $results = $this->fetchQuery($query, array_values($userIds));
        foreach ($results as $result) {
            $existsUsers[$result[self::COLUMN_USER_ID]] = $result[self::COLUMN_USER_ID];
        }
        return $existsUsers ?? [];
    }

    /**
     * @return common_persistence_SqlPersistence
     */
    private function getPersistence()
    {
        if (!$this->persistence) {
            $this->persistence = $this->getServiceLocator()->get(PersistenceManager::SERVICE_ID)
                ->getPersistenceById($this->getOption(self::OPTION_PERSISTENCE));
        }
        return $this->persistence;
    }


    /**
     * @param string $query
     * @param array $params
     * @return array
     */
    private function fetchQuery($query, $params)
    {
        $statement = $this->getPersistence()->query($query, $params);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function createTables()
    {
        $schemaManager = $this->getPersistence()->getDriver()->getSchemaManager();
        $schema = $schemaManager->createSchema();
        $fromSchema = clone $schema;
        $table = $schema->createtable(self::TABLE_PRIVILEGES_NAME);
        $table->addColumn(self::COLUMN_USER_ID, 'string', ['notnull' => null, 'length' => 255]);
        $table->addColumn(self::COLUMN_RESOURCE_ID, 'string', ['notnull' => null, 'length' => 255]);
        $table->addColumn(self::COLUMN_PRIVILEGE, 'string', ['notnull' => null, 'length' => 255]);
        $table->setPrimaryKey([self::COLUMN_USER_ID, self::COLUMN_RESOURCE_ID, self::COLUMN_PRIVILEGE]);

        $queries = $this->getPersistence()->getPlatform()->getMigrateSchemaSql($fromSchema, $schema);
        foreach ($queries as $query) {
            $this->getPersistence()->exec($query);
        }
    }


    public function removeTables()
    {
        $persistence = $this->getPersistence();
        $schema = $persistence->getDriver()->getSchemaManager()->createSchema();
        $fromSchema = clone $schema;
        $table = $schema->dropTable(self::TABLE_PRIVILEGES_NAME);
        $queries = $persistence->getPlatform()->getMigrateSchemaSql($fromSchema, $schema);
        foreach ($queries as $query) {
            $persistence->exec($query);
        }
    }
}
