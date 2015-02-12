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
 * Copyright (c) 2013 Open Assessment Technologies S.A.
 * 
 */

namespace oat\taoWorkspace\model\lockStrategy;

use oat\taoRevision\helper\CloneHelper;
use core_kernel_classes_Resource;
use core_kernel_classes_Class;
use core_kernel_classes_Property;
use oat\generis\model\data\ModelManager;
use common_Utils;
use oat\taoWorkspace\model\generis\WrapperModel;
use \oat\tao\model\lock\LockSystem as LockSystemInterface;
use oat\oatbox\Configurable;

/**
 * Implements Lock using a basic property in the ontology storing the lock data
 *
 * @note It would be preferably static but we may want to have the polymorphism on lock but it would be prevented by explicit class method static calls.
 * Also if you nevertheless call it statically you may want to avoid the late static binding for the getLockProperty
 */
class LockSystem extends Configurable
    implements LockSystemInterface
{
    public function getStorage() {
        return new SqlStorage();
    }
    
    /**
     * (non-PHPdoc)
     * @see \oat\tao\model\lock\LockSystem::setLock()
     */
    public function setLock(core_kernel_classes_Resource $resource, $ownerId)
    {
        $lock = $this->getLockData($resource);
        if (is_null($lock)) {
            $clone = $this->deepClone($resource);
            SqlStorage::add($ownerId, $resource, $clone);
        } elseif ($lock->getOwnerId() != $ownerId) {
            throw new ResourceLockedException($lock);
        }
    }
    
    /**
     * (non-PHPdoc)
     * @see \oat\tao\model\lock\LockSystem::isLocked()
     */
    public function isLocked(core_kernel_classes_Resource $resource)
    {
        $lock = $this->getLockData($resource);
        return !is_null($lock);
    }
    
	/**
	 * (non-PHPdoc)
	 * @see \oat\tao\model\lock\LockSystem::releaseLock()
	 */
	public function releaseLock(core_kernel_classes_Resource $resource, $ownerId)
	{
	    $lock = $this->getLockData($resource);
	    if ($lock === false) {
	        return false;
	    }
	    if ($lock->getOwnerId() !== $ownerId) {
	        throw new common_exception_Unauthorized ( "The resource is owned by " . $lockdata->getOwnerId ());
	    }

	    $data = $this->applyData($lock->getWorkCopy(), $resource);
	    SqlStorage::remove($ownerId, $resource);
	    // persistence needs update
	     
	    return true;
	}
	
   /**
    * (non-PHPdoc)
    * @see \oat\tao\model\lock\LockSystem::forceReleaseLock()
    */
    public function forceReleaseLock(core_kernel_classes_Resource $resource)
    {
        $copy = SqlStorage::getWorkCopy($resource);
        $copy->delete();
        parent::forceReleaseLock($resource);
    }
    
    /**
     * (non-PHPdoc)
     * @see \oat\tao\model\lock\LockSystem::getLockData()
     * @return Lock
     */
    public function getLockData(core_kernel_classes_Resource $resource)
    {
        return $this->getStorage()->getLock($resource);
    }
    
    protected function deepClone(core_kernel_classes_Resource $source) {
        $clonedTriples = CloneHelper::deepCloneTriples($source->getRdfTriples());
        $newUri = common_Utils::getNewUri();
        $rdfInterface = ModelManager::getModel()->getRdfInterface();
        foreach ($clonedTriples as $triple) {
            $triple->subject = $newUri;
            $rdfInterface->add($triple);
        }
        return new core_kernel_classes_Resource($newUri);
    }
    
    protected function applyData(core_kernel_classes_Resource $source, core_kernel_classes_Resource $destination)
    {
        $model = ModelManager::getModel();
        
        if (!$model instanceof WrapperModel) {
            throw new \common_exception_InconsistentData('Unexpected ontology model');
        }
        
        \common_Logger::i($source->getUri().' replaces '.$destination->getUri());
        
        $innerModel = $model->getInnerModel();
        $innerModel->getRdfsInterface()->getResourceImplementation()->delete($destination);
        
        foreach ($innerModel->getRdfsInterface()->getResourceImplementation()->getRdfTriples($source) as $triple) {
            $triple->subject = $destination->getUri();
            \common_Logger::i($triple->subject.' '.$triple->predicate.' '.$triple->object);
            $innerModel->getRdfInterface()->add($triple);
        }
    }
}