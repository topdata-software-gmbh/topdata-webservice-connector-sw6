<?php declare(strict_types=1);

namespace Topdata\TopdataConnectorSW6\Core\Content\Device\Agregate\DeviceCustomer;

use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;
use Shopware\Core\Content\Media\MediaEntity;
use Topdata\TopdataConnectorSW6\Core\Content\Device\DeviceEntity;
use Shopware\Core\Checkout\Customer\CustomerEntity;

class DeviceCustomerEntity extends Entity
{
    use EntityIdTrait;
    
    public const DEVICES = 'devices';
    public const DEVICE_NAME = 'name';
    public const DEVICE_NUMBER = 'number';
    public const DEVICE_LOCATION = 'location';
    public const USER = 'user';
    public const DEVICE_NOTES = 'notes';
    public const DEVICE_TIME = 'datetime';
    
    /**
     * @var string
     */
    protected $deviceId;

    /**
     * @var string
     */
    protected $customerId;

    /**
     * @var string|null
     */
    protected $extraInfo;
    
    protected $_extraInfo = null;

    /**
     * @var DeviceEntity
     */
    protected $device;
    
    /**
     * @var CustomerEntity
     */
    protected $customer;
    
    /**
     * @var array|null
     */
    protected $info = null;
    
    /**
     * @var string|null
     */
    protected $customerExtraId;

    
    public function getDeviceId(): string
    {
        return $this->deviceId;
    }
    
    public function setDeviceId(string $deviceId): void
    {
        $this->deviceId = $deviceId;
    }
    
    public function getCustomer(): CustomerEntity
    {
        return $this->customer;
    }

    public function setCustomer(CustomerEntity $customer): void
    {
        $this->customer = $customer;
    }
    
    public function getDevice(): DeviceEntity
    {
        return $this->device;
    }

    public function setDevice(DeviceEntity $device): void
    {
        $this->device = $device;
    }
    
    
    public function getExtraInfo(): array
    {
        if($this->_extraInfo === null) {
            $this->_extraInfo = static::defaultExtraInfo();
            if($this->extraInfo !== null) {
                $this->_extraInfo = json_decode($this->extraInfo, true);
            }
        }
        
        return $this->_extraInfo;
    }

    public function setExtraInfo(array $extraInfo): void
    {
        $this->_extraInfo = $extraInfo;
        $this->extraInfo = json_encode($extraInfo);
    }
    
    public static function defaultExtraInfo($amount = 0) : array
    {
        $amount = (int)$amount;
        if($amount < 0) {
            $amount = 0;
        }
        $return = [static::DEVICES => []];
        for($i=1; $i<=$amount; $i++) {
            $return[static::DEVICES][] = [
                    static::DEVICE_NAME => 'Device '.$i,
                    static::DEVICE_NUMBER => '', 
                    static::DEVICE_LOCATION => '',
                    static::USER => '',
                    static::DEVICE_NOTES => '',
                    static::DEVICE_TIME => date('Y-m-d H:i:s')
                ];
        }
        return $return;
    }
    
    public function getCustomerExtraId() : ?string
    {
        return $this->customerExtraId;
    }
    
    public function setCustomerExtraId(?string $id): void
    {
        $this->customerExtraId = $id;
    }
}
