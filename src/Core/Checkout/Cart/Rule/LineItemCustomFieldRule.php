<?php

declare(strict_types=1);

namespace Shopware\Core\Checkout\Cart\Rule;

use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Framework\App\Manifest\Xml\CustomFieldTypes\MultiEntitySelectField;
use Shopware\Core\Framework\App\Manifest\Xml\CustomFieldTypes\MultiSelectField;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Rule\Exception\UnsupportedOperatorException;
use Shopware\Core\Framework\Rule\Rule;
use Shopware\Core\Framework\Rule\RuleComparison;
use Shopware\Core\Framework\Rule\RuleScope;
use Shopware\Core\Framework\Util\FloatComparator;
use Shopware\Core\System\CustomField\CustomFieldTypes;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Constraints\Choice;
use Symfony\Component\Validator\Constraints\NotBlank;

#[Package('business-ops')]
class LineItemCustomFieldRule extends Rule
{
    final public const RULE_NAME = 'cartLineItemCustomField';

    /**
     * @var array|string|int|float|bool|null
     */
    protected $renderedFieldValue;

    protected ?string $selectedField = null;

    protected ?string $selectedFieldSet = null;

    /**
     * @param array<string, mixed> $renderedField
     *
     * @internal
     */
    public function __construct(
        protected string $operator = self::OPERATOR_EQ,
        protected array $renderedField = []
    ) {
        parent::__construct();
    }

    /**
     * @throws UnsupportedOperatorException
     */
    public function match(RuleScope $scope): bool
    {
        if ($scope instanceof LineItemScope) {
            return $this->isCustomFieldValid($scope->getLineItem());
        }

        if (!$scope instanceof CartRuleScope) {
            return false;
        }

        foreach ($scope->getCart()->getLineItems()->filterGoodsFlat() as $lineItem) {
            if ($this->isCustomFieldValid($lineItem)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array|Constraint[][]
     */
    public function getConstraints(): array
    {
        return [
            'renderedField' => [new NotBlank()],
            'selectedField' => [new NotBlank()],
            'selectedFieldSet' => [new NotBlank()],
            'renderedFieldValue' => $this->getRenderedFieldValueConstraints(),
            'operator' => [
                new NotBlank(),
                new Choice(
                    [
                        self::OPERATOR_NEQ,
                        self::OPERATOR_GTE,
                        self::OPERATOR_LTE,
                        self::OPERATOR_EQ,
                        self::OPERATOR_GT,
                        self::OPERATOR_LT,
                    ]
                ),
            ],
        ];
    }

    /**
     * @throws UnsupportedOperatorException
     */
    private function isCustomFieldValid(LineItem $lineItem): bool
    {
        $customFields = $lineItem->getPayloadValue('customFields');
        if ($customFields === null) {
            return RuleComparison::isNegativeOperator($this->operator);
        }

        $actual = $this->getValue($customFields, $this->renderedField);
        $expected = $this->getExpectedValue($this->renderedFieldValue, $this->renderedField);

        if ($actual === null) {
            if ($this->operator === self::OPERATOR_NEQ) {
                return $actual !== $expected;
            }

            return false;
        }

        if (self::isFloat($this->renderedField)) {
            return self::floatMatch($this->operator, (float) $actual, (float) $expected);
        }

        if (self::isArray($this->renderedField)) {
            return self::arrayMatch($this->operator, (array) $actual, (array) $expected);
        }

        return match ($this->operator) {
            self::OPERATOR_NEQ => $actual !== $expected,
            self::OPERATOR_GTE => $actual >= $expected,
            self::OPERATOR_LTE => $actual <= $expected,
            self::OPERATOR_EQ => $actual === $expected,
            self::OPERATOR_GT => $actual > $expected,
            self::OPERATOR_LT => $actual < $expected,
            default => throw new UnsupportedOperatorException($this->operator, self::class),
        };
    }

    private static function floatMatch(string $operator, float $actual, float $expected): bool
    {
        return match ($operator) {
            Rule::OPERATOR_NEQ => FloatComparator::notEquals($actual, $expected),
            Rule::OPERATOR_GTE => FloatComparator::greaterThanOrEquals($actual, $expected),
            Rule::OPERATOR_LTE => FloatComparator::lessThanOrEquals($actual, $expected),
            Rule::OPERATOR_EQ => FloatComparator::equals($actual, $expected),
            Rule::OPERATOR_GT => FloatComparator::greaterThan($actual, $expected),
            Rule::OPERATOR_LT => FloatComparator::lessThan($actual, $expected),
            default => throw new UnsupportedOperatorException($operator, self::class),
        };
    }

    private static function arrayMatch(string $operator, array $actual, array $expected): bool
    {
        return match ($operator) {
            Rule::OPERATOR_NEQ => \count(array_intersect($actual, $expected)) === 0,
            Rule::OPERATOR_EQ => \count(array_intersect($actual, $expected)) > 0,
            default => throw new UnsupportedOperatorException($operator, self::class),
        };
    }

    /**
     * @return Constraint[]
     */
    private function getRenderedFieldValueConstraints(): array
    {
        $constraints = [];

        if (!\array_key_exists('type', $this->renderedField)) {
            return [new NotBlank()];
        }

        if ($this->renderedField['type'] !== CustomFieldTypes::BOOL) {
            $constraints[] = new NotBlank();
        }

        return $constraints;
    }

    /**
     * @param array<string, mixed> $customFields
     * @param array<string, mixed> $renderedField
     *
     * @return string|int|float|bool|null
     */
    private function getValue(array $customFields, array $renderedField): mixed
    {
        if (\in_array($renderedField['type'], [CustomFieldTypes::BOOL, CustomFieldTypes::SWITCH], true)) {
            if (!empty($customFields) && \array_key_exists($this->renderedField['name'], $customFields)) {
                return $customFields[$renderedField['name']];
            }

            return false;
        }

        if (!empty($customFields) && \array_key_exists($this->renderedField['name'], $customFields)) {
            return $customFields[$renderedField['name']];
        }

        return null;
    }

    /**
     * @param array|string|int|float|bool|null $renderedFieldValue
     * @param array<string, mixed> $renderedField
     *
     * @return array|string|int|float|bool|null
     */
    private function getExpectedValue($renderedFieldValue, array $renderedField)
    {
        if (\in_array($renderedField['type'], [CustomFieldTypes::BOOL, CustomFieldTypes::SWITCH], true)) {
            return (bool) ($renderedFieldValue ?? false); // those fields are initialized with null in the rule builder
        }

        return $renderedFieldValue;
    }

    /**
     * @param array<string, string> $renderedField
     */
    private static function isFloat(array $renderedField): bool
    {
        return $renderedField['type'] === CustomFieldTypes::FLOAT;
    }

    /**
     * @param array<string, string> $renderedField
     */
    private static function isArray(array $renderedField): bool
    {
        if ($renderedField['type'] !== CustomFieldTypes::SELECT) {
            return false;
        }

        if (!\array_key_exists('componentName', $renderedField['config'])) {
            return false;
        }

        if ($renderedField['config']['componentName'] === MultiSelectField::COMPONENT_NAME) {
            return true;
        }

        if ($renderedField['config']['componentName'] === MultiEntitySelectField::COMPONENT_NAME) {
            return true;
        }

        return false;
    }
}
