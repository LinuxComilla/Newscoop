<?php

namespace Proxy\__CG__\Newscoop\Image;

/**
 * THIS CLASS WAS GENERATED BY THE DOCTRINE ORM. DO NOT EDIT THIS FILE.
 */
class ArticleImage extends \Newscoop\Image\ArticleImage implements \Doctrine\ORM\Proxy\Proxy
{
    private $_entityPersister;
    private $_identifier;
    public $__isInitialized__ = false;
    public function __construct($entityPersister, $identifier)
    {
        $this->_entityPersister = $entityPersister;
        $this->_identifier = $identifier;
    }
    /** @private */
    public function __load()
    {
        if (!$this->__isInitialized__ && $this->_entityPersister) {
            $this->__isInitialized__ = true;

            if (method_exists($this, "__wakeup")) {
                // call this after __isInitialized__to avoid infinite recursion
                // but before loading to emulate what ClassMetadata::newInstance()
                // provides.
                $this->__wakeup();
            }

            if ($this->_entityPersister->load($this->_identifier, $this) === null) {
                throw new \Doctrine\ORM\EntityNotFoundException();
            }
            unset($this->_entityPersister, $this->_identifier);
        }
    }

    /** @private */
    public function __isInitialized()
    {
        return $this->__isInitialized__;
    }

    
    public function getArticleNumber()
    {
        if ($this->__isInitialized__ === false) {
            return (int) $this->_identifier["articleNumber"];
        }
        $this->__load();
        return parent::getArticleNumber();
    }

    public function getImage()
    {
        $this->__load();
        return parent::getImage();
    }

    public function getId()
    {
        $this->__load();
        return parent::getId();
    }

    public function getPath()
    {
        $this->__load();
        return parent::getPath();
    }

    public function getWidth()
    {
        $this->__load();
        return parent::getWidth();
    }

    public function getHeight()
    {
        $this->__load();
        return parent::getHeight();
    }

    public function setIsDefault($isDefault = false)
    {
        $this->__load();
        return parent::setIsDefault($isDefault);
    }

    public function isDefault()
    {
        $this->__load();
        return parent::isDefault();
    }


    public function __sleep()
    {
        return array('__isInitialized__', 'articleNumber', 'number', 'isDefault', 'image');
    }

    public function __clone()
    {
        if (!$this->__isInitialized__ && $this->_entityPersister) {
            $this->__isInitialized__ = true;
            $class = $this->_entityPersister->getClassMetadata();
            $original = $this->_entityPersister->load($this->_identifier);
            if ($original === null) {
                throw new \Doctrine\ORM\EntityNotFoundException();
            }
            foreach ($class->reflFields as $field => $reflProperty) {
                $reflProperty->setValue($this, $reflProperty->getValue($original));
            }
            unset($this->_entityPersister, $this->_identifier);
        }
        
    }
}