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
 * Foundation, Inc., 31 Milk St # 960789 Boston, MA 02196 USA.
 *
 * Copyright (c) 2026 (original work) Open Assessment Technologies SA;
 *
 */

declare(strict_types=1);

namespace oat\taoWorkspace\test\unit\model\lockStrategy;

use common_exception_InconsistentData;
use common_persistence_SqlPersistence;
use core_kernel_classes_Resource;
use Doctrine\DBAL\Result;
use oat\taoWorkspace\model\lockStrategy\Lock;
use oat\taoWorkspace\model\lockStrategy\SqlStorage;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for SqlStorage: getMap (DBAL fetch path) and getLock (single/multiple-lock scenarios).
 */
class SqlStorageTest extends TestCase
{
    protected function tearDown(): void
    {
        $this->setSqlStoragePersistence(null);
        parent::tearDown();
    }

    /**
     * Inject persistence into SqlStorage via reflection (private static $persistence).
     */
    private function setSqlStoragePersistence(?object $persistence): void
    {
        $ref = new \ReflectionClass(SqlStorage::class);
        $prop = $ref->getProperty('persistence');
        $prop->setAccessible(true);
        $prop->setValue(null, $persistence);
    }

    /**
     * Build a persistence mock that returns the given Result from query().
     */
    private function createPersistenceMock(Result $result): common_persistence_SqlPersistence
    {
        $persistence = $this->createMock(common_persistence_SqlPersistence::class);
        $persistence
            ->method('query')
            ->willReturn($result);
        return $persistence;
    }

    // --- getMap: DBAL fetch path (lines 78–90) ---

    public function testGetMapEmptyResultReturnsEmptyMap(): void
    {
        $resultMock = $this->createMock(Result::class);
        $resultMock
            ->method('fetchAssociative')
            ->willReturn(false);

        $persistence = $this->createPersistenceMock($resultMock);
        $this->setSqlStoragePersistence($persistence);

        $map = SqlStorage::getMap('user-1');
        $this->assertSame([], $map);
    }

    public function testGetMapSingleRowMapsResourceToWorkcopy(): void
    {
        $row = [
            SqlStorage::FIELD_OWNER   => 'user-1',
            SqlStorage::FIELD_RESOURCE => 'http://resource/1',
            SqlStorage::FIELD_WORKCOPY => 'http://workcopy/1',
            SqlStorage::FIELD_CREATED  => '123',
        ];
        $resultMock = $this->createMock(Result::class);
        $resultMock
            ->method('fetchAssociative')
            ->willReturnOnConsecutiveCalls($row, false);

        $persistence = $this->createPersistenceMock($resultMock);
        $this->setSqlStoragePersistence($persistence);

        $map = SqlStorage::getMap('user-1');
        $this->assertSame(['http://resource/1' => 'http://workcopy/1'], $map);
    }

    public function testGetMapMultipleRowsBuildsCorrectMap(): void
    {
        $row1 = [
            SqlStorage::FIELD_OWNER   => 'user-1',
            SqlStorage::FIELD_RESOURCE => 'http://resource/1',
            SqlStorage::FIELD_WORKCOPY => 'http://workcopy/1',
            SqlStorage::FIELD_CREATED  => '123',
        ];
        $row2 = [
            SqlStorage::FIELD_OWNER   => 'user-1',
            SqlStorage::FIELD_RESOURCE => 'http://resource/2',
            SqlStorage::FIELD_WORKCOPY => 'http://workcopy/2',
            SqlStorage::FIELD_CREATED  => '456',
        ];
        $resultMock = $this->createMock(Result::class);
        $resultMock
            ->method('fetchAssociative')
            ->willReturnOnConsecutiveCalls($row1, $row2, false);

        $persistence = $this->createPersistenceMock($resultMock);
        $this->setSqlStoragePersistence($persistence);

        $map = SqlStorage::getMap('user-1');
        $this->assertSame([
            'http://resource/1' => 'http://workcopy/1',
            'http://resource/2' => 'http://workcopy/2',
        ], $map);
    }

    // --- getLock: retrieval path (lines 113–135), single vs multiple locks ---

    public function testGetLockNoRowsReturnsNull(): void
    {
        $resultMock = $this->createMock(Result::class);
        $resultMock
            ->method('fetchAssociative')
            ->willReturn(false);

        $persistence = $this->createPersistenceMock($resultMock);
        $this->setSqlStoragePersistence($persistence);

        $storage = new SqlStorage();
        $resource = new core_kernel_classes_Resource('http://resource/1');
        $lock = $storage->getLock($resource);
        $this->assertNull($lock);
    }

    public function testGetLockSingleRowReturnsLock(): void
    {
        $row = [
            SqlStorage::FIELD_OWNER   => 'owner-1',
            SqlStorage::FIELD_RESOURCE => 'http://resource/1',
            SqlStorage::FIELD_WORKCOPY => 'http://workcopy/1',
            SqlStorage::FIELD_CREATED  => '123',
        ];
        $resultMock = $this->createMock(Result::class);
        $resultMock
            ->method('fetchAssociative')
            ->willReturnOnConsecutiveCalls($row, false);

        $persistence = $this->createPersistenceMock($resultMock);
        $this->setSqlStoragePersistence($persistence);

        $storage = new SqlStorage();
        $resource = new core_kernel_classes_Resource('http://resource/1');
        $lock = $storage->getLock($resource);
        $this->assertInstanceOf(Lock::class, $lock);
        $this->assertSame('http://resource/1', $lock->getResource()->getUri());
        $this->assertSame('owner-1', $lock->getOwnerId());
        $this->assertSame('http://workcopy/1', $lock->getWorkCopy()->getUri());
    }

    public function testGetLockMultipleRowsThrowsInconsistentData(): void
    {
        $row1 = [
            SqlStorage::FIELD_OWNER   => 'owner-1',
            SqlStorage::FIELD_RESOURCE => 'http://resource/1',
            SqlStorage::FIELD_WORKCOPY => 'http://workcopy/1',
            SqlStorage::FIELD_CREATED  => '123',
        ];
        $row2 = [
            SqlStorage::FIELD_OWNER   => 'owner-2',
            SqlStorage::FIELD_RESOURCE => 'http://resource/1',
            SqlStorage::FIELD_WORKCOPY => 'http://workcopy/2',
            SqlStorage::FIELD_CREATED  => '456',
        ];
        $resultMock = $this->createMock(Result::class);
        $resultMock
            ->method('fetchAssociative')
            ->willReturnOnConsecutiveCalls($row1, $row2, false);

        $persistence = $this->createPersistenceMock($resultMock);
        $this->setSqlStoragePersistence($persistence);

        $storage = new SqlStorage();
        $resource = new core_kernel_classes_Resource('http://resource/1');

        $this->expectException(common_exception_InconsistentData::class);
        $this->expectExceptionMessage('2 locks found for resource http://resource/1');
        $storage->getLock($resource);
    }

    public function testGetLockMultipleRowsExceptionMessageContainsCountAndUri(): void
    {
        $row1 = [
            SqlStorage::FIELD_OWNER   => 'owner-1',
            SqlStorage::FIELD_RESOURCE => 'http://example.org/res',
            SqlStorage::FIELD_WORKCOPY => 'http://workcopy/1',
            SqlStorage::FIELD_CREATED  => '1',
        ];
        $row2 = [
            SqlStorage::FIELD_OWNER   => 'owner-2',
            SqlStorage::FIELD_RESOURCE => 'http://example.org/res',
            SqlStorage::FIELD_WORKCOPY => 'http://workcopy/2',
            SqlStorage::FIELD_CREATED  => '2',
        ];
        $resultMock = $this->createMock(Result::class);
        $resultMock
            ->method('fetchAssociative')
            ->willReturnOnConsecutiveCalls($row1, $row2, false);

        $persistence = $this->createPersistenceMock($resultMock);
        $this->setSqlStoragePersistence($persistence);

        $storage = new SqlStorage();
        $resource = new core_kernel_classes_Resource('http://example.org/res');

        try {
            $storage->getLock($resource);
            $this->fail('Expected common_exception_InconsistentData');
        } catch (common_exception_InconsistentData $e) {
            $this->assertStringContainsString('2 locks found', $e->getMessage());
            $this->assertStringContainsString('http://example.org/res', $e->getMessage());
        }
    }
}
