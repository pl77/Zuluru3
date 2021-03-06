<?php
namespace App\Model\Table;

use ArrayObject;
use Cake\Core\Configure;
use Cake\Datasource\EntityInterface;
use Cake\Datasource\Exception\RecordNotFoundException;
use Cake\Event\Event as CakeEvent;
use Cake\ORM\RulesChecker;
use Cake\Routing\Router;
use Cake\Validation\Validator;
use App\Model\Rule\InConfigRule;

/**
 * Payments Model
 *
 * @property \Cake\ORM\Association\BelongsTo $Registrations
 * @property \Cake\ORM\Association\BelongsTo $RegistrationAudits
 */
class PaymentsTable extends AppTable {

	/**
	 * Initialize method
	 *
	 * @param array $config The configuration for the Table.
	 * @return void
	 */
	public function initialize(array $config) {
		parent::initialize($config);

		$this->table('payments');
		$this->displayField('id');
		$this->primaryKey('id');

		$this->addBehavior('Timestamp');

		// Only set the person_id field if the user is logged in: when we get
		// payments from a payment processor, we want to leave those fields null.
		$identity = Router::getRequest() ? Router::getRequest()->getAttribute('identity') : null;
		if ($identity && $identity->isLoggedIn()) {
			$this->addBehavior('Muffin/Footprint.Footprint', [
				'events' => [
					'Model.beforeSave' => [
						'created_person_id' => 'new',
						'updated_person_id' => 'always',
					],
				],
				'propertiesMap' => [
					'created_person_id' => '_footprint.person.id',
					'updated_person_id' => '_footprint.person.id',
				],
			]);
		}

		$this->belongsTo('Registrations', [
			'foreignKey' => 'registration_id',
			'joinType' => 'INNER',
		]);
		$this->belongsTo('RegistrationAudits', [
			'foreignKey' => 'registration_audit_id',
		]);
	}

	/**
	 * Default validation rules.
	 *
	 * @param \Cake\Validation\Validator $validator Validator instance.
	 * @return \Cake\Validation\Validator
	 */
	public function validationDefault(Validator $validator) {
		$validator
			->numeric('id')
			->allowEmpty('id', 'create')

			->requirePresence('payment_type', 'create')
			->notEmpty('payment_type')

			->numeric('payment_amount')
			->notEmpty('payment_amount')

			->numeric('refunded_amount')
			->allowEmpty('refunded_amount')

			->allowEmpty('notes')

			->requirePresence('payment_method', 'create')
			->notEmpty('payment_method')

			;

		return $validator;
	}

	/**
	 * Validation rule for positive amounts, with a varying message depending on the type.
	 *
	 * @param \Cake\Validation\Validator $validator Validator instance.
	 * @param mixed $type Type of "payment" being recorded.
	 * @return \Cake\Validation\Validator
	 */
	public function validationAmount(Validator $validator, $type, $rule = ['comparison', '>', 0]) {
		return $this->validationDefault($validator)
			->add('payment_amount', 'valid', ['rule' => $rule, 'message' => __('{0} amounts must be positive.', $type)]);
	}

	public function validationPayment(Validator $validator) {
		return $this->validationAmount($validator, __('Payment'));
	}

	public function validationRefund(Validator $validator) {
		return $this->validationAmount($validator, __('Refund'), ['comparison', '<', 0]);
	}

	public function validationCredit(Validator $validator) {
		return $this->validationAmount($validator, __('Credit'), ['comparison', '<', 0]);
	}

	public function validationTransferFrom(Validator $validator) {
		return $this->validationAmount($validator, __('Transfer'), ['comparison', '<', 0]);
	}

	public function validationTransferTo(Validator $validator) {
		return $this->validationAmount($validator, __('Transfer'));
	}

	/**
	 * Returns a rules checker object that will be used for validating
	 * application integrity.
	 *
	 * @param \Cake\ORM\RulesChecker $rules The rules object to be modified.
	 * @return \Cake\ORM\RulesChecker
	 */
	public function buildRules(RulesChecker $rules) {
		$rules->add(new InConfigRule('options.payment_method'), 'validPaymentMethod', [
			'errorField' => 'payment_method',
			'message' => __('Select a valid payment method.'),
		]);

		$rules->add(function (EntityInterface $entity, Array $options) {
			return ($options['registration']->total_payment <= $options['registration']->total_amount);
		}, 'validPayments', [
			'errorField' => 'payment_amount',
			'message' => __('This would pay more than the amount owing.'),
		]);

		$rules->add(function (EntityInterface $entity, Array $options) {
			return ($entity->refunded_amount <= $entity->payment_amount);
		}, 'validPayments', [
			'errorField' => 'payment_amount',
			'message' => __('This would refund more than the amount paid.'),
		]);

		return $rules;
	}

	/**
	 * Updates data before trying to update the entity.
	 *
	 * @param CakeEvent $cakeEvent Unused
	 * @param ArrayObject $data The data record being patched in
	 * @param ArrayObject $options The options passed to the patchEntity method
	 */
	public function beforeMarshal(CakeEvent $cakeEvent, ArrayObject $data, ArrayObject $options) {
		// If there is no payment type set in the incoming data, determine it based on the payment amount.
		if (empty($data['payment_type'])) {
			if ($data['payment_amount'] == $options['registration']->total_amount) {
				$data['payment_type'] = 'Full';
			} else if ($data['payment_amount'] == $options['registration']->balance) {
				$data['payment_type'] = 'Remaining Balance';
			} else if ($options['registration']->total_payment == 0) {
				$data['payment_type'] = 'Deposit';
			} else {
				$data['payment_type'] = 'Installment';
			}
		}
	}

	public function affiliate($id) {
		try {
			return $this->Registrations->affiliate($this->field('registration_id', ['id' => $id]));
		} catch (RecordNotFoundException $ex) {
			return null;
		}
	}

}
