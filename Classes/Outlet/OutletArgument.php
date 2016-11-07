<?php
namespace FluidTYPO3\Flux\Outlet;

/*
 * This file is part of the FluidTYPO3/Flux project under GPLv2 or later.
 *
 * For the full copyright and license information, please read the
 * LICENSE.md file that was distributed with this source code.
 */


use TYPO3\CMS\Extbase\Mvc\Controller\MvcPropertyMappingConfiguration;
use TYPO3\CMS\Extbase\Property\Exception\TargetNotFoundException;
use TYPO3\CMS\Extbase\Utility\TypeHandlingUtility;
use TYPO3\CMS\Extbase\Validation\Exception\NoSuchValidatorException;
use TYPO3\CMS\Extbase\Validation\Validator\ConjunctionValidator;
use TYPO3\CMS\Extbase\Validation\ValidatorResolver;

/**
 * A outlet argument
 *
 * @api
 */
class OutletArgument
{
    /**
     * @var \TYPO3\CMS\Extbase\Property\PropertyMapper
     */
    protected $propertyMapper;

    /**
     * @var MvcPropertyMappingConfiguration
     */
    protected $propertyMappingConfiguration;

    /**
     * @var \TYPO3\CMS\Extbase\Validation\ValidatorResolver
     * @inject
     */
    protected $validatorResolver;

    /**
     * Name of this argument
     *
     * @var string
     */
    protected $name = '';

    /**
     * Data type of this argument's value
     *
     * @var string
     */
    protected $dataType = null;

    /**
     * Actual value of this argument
     *
     * @var mixed
     */
    protected $value = null;

    /**
     * A custom validator, used supplementary to the base validation
     *
     * @var array<\TYPO3\CMS\Extbase\Validation\Validator\ValidatorInterface>
     */
    protected $validators = null;

    /**
     * The validation results. This can be asked if the argument has errors.
     *
     * @var \TYPO3\CMS\Extbase\Error\Result
     */
    protected $validationResults = null;

    /**
     * @param \TYPO3\CMS\Extbase\Property\PropertyMapper $propertyMapper
     */
    public function injectPropertyMapper(\TYPO3\CMS\Extbase\Property\PropertyMapper $propertyMapper)
    {
        $this->propertyMapper = $propertyMapper;
    }

    /**
     * @param \TYPO3\CMS\Extbase\Mvc\Controller\MvcPropertyMappingConfiguration $propertyMappingConfiguration
     */
    public function injectPropertyMappingConfiguration(MvcPropertyMappingConfiguration $propertyMappingConfiguration)
    {
        $this->propertyMappingConfiguration = $propertyMappingConfiguration;
    }

    /**
     * @param \TYPO3\CMS\Extbase\Validation\ValidatorResolver $validatorResolver
     */
    public function injectValidatorResolver(ValidatorResolver $validatorResolver)
    {
        $this->validatorResolver = $validatorResolver;
    }

    /**
     * Constructs this controller argument
     *
     * @param string $name Name of this argument
     * @param string $dataType The data type of this argument
     * @throws \InvalidArgumentException if $name is not a string or empty
     * @api
     */
    public function __construct($name, $dataType)
    {
        if (!is_string($name)) {
            throw new \InvalidArgumentException('$name must be of type string, ' . gettype($name) . ' given.', 1187951688);
        }
        if ($name === '') {
            throw new \InvalidArgumentException('$name must be a non-empty string.', 1232551853);
        }
        $this->name = $name;
        $this->dataType = TypeHandlingUtility::normalizeType($dataType);
    }

    /**
     * Returns the name of this argument
     *
     * @return string This argument's name
     * @api
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * Returns the data type of this argument's value
     *
     * @return string The data type
     * @api
     */
    public function getDataType()
    {
        return $this->dataType;
    }

    /**
     * Sets custom validators which are used supplementary to the base validation
     *
     * @param array<\TYPO3\CMS\Extbase\Validation\Validator\ValidatorInterface> $validators The actual validator object
     * @return \TYPO3\CMS\Extbase\Mvc\Controller\Argument Returns $this (used for fluent interface)
     * @api
     */
    public function setValidators($validators)
    {
        $this->validators = $validators;
        return $this;
    }

    /**
     * Returns the set validators
     *
     * @return array<\TYPO3\CMS\Extbase\Validation\Validator\ValidatorInterface> The set validators
     * @api
     */
    public function getValidators()
    {
        return $this->validators;
    }

    /**
     * @param string $type
     * @param array $options
     */
    public function addValidator($type, $options) {
        if (!is_array($options)) {
            $options = array();
        }
        $validator = $this->validatorResolver->createValidator($type, $options);
        if ($validator === NULL) {
            throw new NoSuchValidatorException('Unknown Validator: ' . $type, 1478559461 );
        }
        $this->validators[] = $validator;
    }

    /**
     * Sets the value of this argument.
     *
     * @param mixed $rawValue The value of this argument
     *
     * @return \TYPO3\CMS\Extbase\Mvc\Controller\Argument
     * @throws \TYPO3\CMS\Extbase\Property\Exception
     */
    public function setValue($rawValue)
    {
        $this->value = $this->propertyMapper->convert($rawValue, $this->dataType, $this->propertyMappingConfiguration);

        $this->validationResults = $this->propertyMapper->getMessages();
        if (count($this->validators) > 0) {
            $conjunctionValidator = new ConjunctionValidator();
            foreach ($this->validators as $validator) {
                $conjunctionValidator->addValidator($validator);
            }
            $validationMessages = $conjunctionValidator->validate($this->value);
            $this->validationResults->merge($validationMessages);
        }
        return $this;
    }

    /**
     * Returns the value of this argument
     *
     * @return mixed The value of this argument - if none was set, NULL is returned
     * @api
     */
    public function getValue()
    {
        if ($this->value === null) {
            return $this->defaultValue;
        } else {
            return $this->value;
        }
    }

    /**
     * Return the Property Mapping Configuration used for this argument; can be used by the initialize*action to modify the Property Mapping.
     *
     * @return \TYPO3\CMS\Extbase\Mvc\Controller\MvcPropertyMappingConfiguration
     * @api
     */
    public function getPropertyMappingConfiguration()
    {
        return $this->propertyMappingConfiguration;
    }

    /**
     * @return bool TRUE if the argument is valid, FALSE otherwise
     * @api
     */
    public function isValid()
    {
        if ($this->validationResults === NULL) {
            return TRUE;
        }
        return !$this->validationResults->hasErrors();
    }

    /**
     * @return \TYPO3\CMS\Extbase\Error\Result Validation errors which have occurred.
     * @api
     */
    public function getValidationResults()
    {
        return $this->validationResults;
    }

    /**
     * Returns a string representation of this argument's value
     *
     * @return string
     * @api
     */
    public function __toString()
    {
        return (string)$this->value;
    }
}
