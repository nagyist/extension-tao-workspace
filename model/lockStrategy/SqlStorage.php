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
 * Copyright (c) 2015 (original work) Open Assessment Technologies SA;
 *
 */

declare(strict_types=1);

namespace oat\taoWorkspace\model\lockStrategy;

use common_persistence_Manager;
use core_kernel_classes_Resource;
use oat\oatbox\service\ConfigurableService;
use oat\oatbox\service\ServiceManager;

class SqlStorage extends ConfigurableService
{
    public const OPTION_PERSISTENCE = 'persistence';

    public const SERVICE_ID = 'taoWorkspace/SqlStorage';

    private static $persistence;

    public const TABLE_NAME = 'workspace';
    public const FIELD_OWNER = 'owner';
    public const FIELD_RESOURCE = 'resource';
    public const FIELD_WORKCOPY = 'workcopy';
    public const FIELD_CREATED = 'created';

    public static function createTable()
    {
        $persistence = self::getPersistence();

        $schemaManager = $persistence->getDriver()->getSchemaManager();
        $schema = $schemaManager->createSchema();
        $fromSchema = clone $schema;

        $tableResults = $schema->createtable(self::TABLE_NAME);
        $tableResults->addColumn(self::FIELD_OWNER, 'string', ['notnull' => true, 'length' => 255]);
        $tableResults->addColumn(self::FIELD_RESOURCE, 'string', ['notnull' => true, 'length' => 255]);
        $tableResults->addColumn(self::FIELD_WORKCOPY, 'string', ['notnull' => true, 'length' => 255]);
        $tableResults->addColumn(self::FIELD_CREATED, 'string', ['notnull' => true]);
        $tableResults->setPrimaryKey([self::FIELD_OWNER, self::FIELD_RESOURCE]);

        $queries = $persistence->getPlatform()->getMigrateSchemaSql($fromSchema, $schema);
        foreach ($queries as $query) {
            $persistence->exec($query);
        }
    }

    /**
     * @return \common_persistence_SqlPersistence
     */
    public static function getPersistence()
    {
        if (!self::$persistence) {
            $self = ServiceManager::getServiceManager()->get(self::SERVICE_ID);
            $persistenceId = $self->getOption(self::OPTION_PERSISTENCE);
            self::$persistence = common_persistence_Manager::getPersistence($persistenceId);
        }

        return self::$persistence;
    }

    public static function getMap($userId)
    {
        $persistence = self::getPersistence();
        $query = 'SELECT * FROM "' . self::TABLE_NAME . '" WHERE "' . self::FIELD_OWNER . '" = ?';
        $result = $persistence->query($query, [$userId]);

        $map = [];
        while (($row = $result->fetchAssociative()) !== false) {
            $map[$row[self::FIELD_RESOURCE]] = $row[self::FIELD_WORKCOPY];
        }

        return $map;
    }

    public static function add($userId, core_kernel_classes_Resource $resource, core_kernel_classes_Resource $copy)
    {
        $persistence = self::getPersistence();
        $query = 'INSERT INTO "' . self::TABLE_NAME . '" ("' . self::FIELD_OWNER . '", "'
            . self::FIELD_RESOURCE . '", "' . self::FIELD_WORKCOPY . '", "' . self::FIELD_CREATED . '") '
            . 'VALUES (?,?,?,?)';
        $persistence->exec($query, [$userId, $resource->getUri(), $copy->getUri(), time()]);
    }

    public static function remove(Lock $lock)
    {
        $persistence = self::getPersistence();
        $query = 'DELETE FROM "' . self::TABLE_NAME . '" WHERE "' . self::FIELD_OWNER . '" = ? AND "'
            . self::FIELD_RESOURCE . '" = ?';
        $persistence->exec($query, [$lock->getOwnerId(), $lock->getResource()->getUri()]);
    }

    /**
     * @param core_kernel_classes_Resource $resource
     * @return mixed|null
     * @throws \common_exception_InconsistentData
     */
    public function getLock(core_kernel_classes_Resource $resource)
    {
        $query = 'SELECT * FROM "' . self::TABLE_NAME . '" WHERE "' . self::FIELD_RESOURCE . '" = ?';
        $result = static::getPersistence()->query($query, [$resource->getUri()]);

        $locks = [];
        while (($row = $result->fetchAssociative()) !== false) {
            $locks[] = new Lock(
                new core_kernel_classes_Resource($row[self::FIELD_RESOURCE]),
                $row[self::FIELD_OWNER],
                $row[self::FIELD_CREATED],
                new core_kernel_classes_Resource($row[self::FIELD_WORKCOPY])
            );
        }

        if (count($locks) > 1) {
            throw new \common_exception_InconsistentData(
                count($locks) . ' locks found for resource ' . $resource->getUri()
            );
        }

        return count($locks) === 1 ? reset($locks) : null;
    }
}
