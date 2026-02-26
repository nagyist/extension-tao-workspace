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
 * Copyright (c) 2016 (original work) Open Assessment Technologies SA;
 *
 */

declare(strict_types=1);

namespace oat\taoWorkspace\scripts\uninstall;

use common_ext_action_InstallAction as InstallAction;
use common_exception_InconsistentData as InconsistentData;
use common_report_Report as Report;
use oat\taoWorkspace\model\lockStrategy\LockSystem;
use oat\generis\model\data\ModelManager;
use oat\taoWorkspace\model\generis\WrapperModel;
use oat\taoRevision\model\Repository;
use oat\taoWorkspace\model\RevisionWrapper;
use oat\tao\model\lock\LockSystem as LockSystemInterface;
use oat\tao\model\lock\implementation\NoLock;
use oat\taoWorkspace\model\lockStrategy\SqlStorage;
use oat\generis\model\OntologyAwareTrait;

class UninstallWorkspace extends InstallAction
{
    use OntologyAwareTrait;

    public function __invoke($params)
    {
        $lock = $this->getServiceManager()->get(LockSystemInterface::SERVICE_ID)->getConfig();
        if (!$lock instanceof LockSystem) {
            throw new InconsistentData(
                'Expected Workspace Lock not found, found ' . get_class($lock)
            );
        }

        $model = ModelManager::getModel();
        if (!$model instanceof WrapperModel) {
            throw new InconsistentData(
                'Expected Ontology Wrapper not found, found ' . get_class($model)
            );
        }

        $repositoryStore = $this->getServiceManager()->get(Repository::SERVICE_ID);
        if (!$repositoryStore instanceof RevisionWrapper) {
            throw new InconsistentData(
                'Expected Revision Wrapper not found, found ' . get_class($repositoryStore)
            );
        }

        $this->releaseAll($lock);

        $innerModel = $model->getInnerModel();
        ModelManager::setModel($innerModel);

        $innerKey = $repositoryStore->getOption(RevisionWrapper::OPTION_INNER_IMPLEMENTATION);
        $innerStore = $this->getServiceManager()->get($innerKey);
        $this->registerService(Repository::SERVICE_ID, $innerStore);
        $this->getServiceManager()->unregister($innerKey);

        $storageSql = $lock->getStorage()->getPersistence();

        $this->registerService(LockSystemInterface::SERVICE_ID, new NoLock());
        $storageSql->exec('DROP TABLE IF EXISTS ' . SqlStorage::TABLE_NAME);

        return new Report(Report::TYPE_SUCCESS, __('Successfully removed workspace wrappers'));
    }

    /**
     * Release all remaining locks, to trigger cleanup
     *
     * @param LockSystem $lockService
     */
    protected function releaseAll(LockSystem $lockService)
    {
        $sql = $lockService->getStorage()->getPersistence();

        $query = 'SELECT ' . SqlStorage::FIELD_OWNER . ', ' . SqlStorage::FIELD_RESOURCE . ' FROM '
            . SqlStorage::TABLE_NAME;
        $result = $sql->query($query);
        $locked = $result->fetchAllAssociative();
        foreach ($locked as $data) {
            try {
                $resource = $this->getResource($data[SqlStorage::FIELD_RESOURCE]);
                $lockService->releaseLock($resource, $data[SqlStorage::FIELD_OWNER]);
            } catch (\Exception $e) {
                \common_Logger::w(
                    'Failed to release resource ' . $data[SqlStorage::FIELD_RESOURCE] . ': ' . $e->getMessage()
                );
            }
        }
    }
}
