<?php
namespace App\Controller;

use App\Authorization\ContextResource;
use App\Model\Entity\Event;
use App\Model\Entity\Registration;
use Cake\Core\Configure;
use Cake\Database\Expression\QueryExpression;
use Cake\Datasource\Exception\InvalidPrimaryKeyException;
use Cake\Datasource\Exception\RecordNotFoundException;
use Cake\I18n\Number;
use Cake\I18n\FrozenTime;
use Cake\ORM\Query;
use Cake\Validation\Validator;
use App\Model\Entity\Response;
use App\Module\EventType as EventTypeBase;

/**
 * Registrations Controller
 *
 * @property \App\Model\Table\RegistrationsTable $Registrations
 */
class RegistrationsController extends AppController {

	/**
	 * _noAuthenticationActions method
	 *
	 * @return array of actions that can be taken even by visitors that are not logged in.
	 */
	protected function _noAuthenticationActions() {
		if (!Configure::read('feature.registration')) {
			return [];
		}

		// 'Payment' comes from the payment processor.
		return ['payment'];
	}

	// TODO: Proper fix for black-holing of payment details posted to us from processors
	public function beforeFilter(\Cake\Event\Event $event) {
		parent::beforeFilter($event);
		if (isset($this->Security)) {
			$this->Security->config('unlockedActions', ['payment']);
		}
	}

	/**
	 * Full list method
	 *
	 * @return void|\Cake\Network\Response
	 */
	public function full_list() {
		$this->paginate['order'] = ['Registrations.payment' => 'DESC', 'Registrations.created' => 'DESC'];
		$id = $this->request->getQuery('event');
		try {
			$event = $this->Registrations->Events->get($id, [
				'contain' => [
					'EventTypes',
					'Questionnaires' => [
						'Questions' => [
							'queryBuilder' => function (Query $q) {
								return $q->where(['Questions.active' => true, 'Questions.anonymous' => false]);
							},
							'Answers',
						],
					],
					'Prices',
					'Divisions' => ['Leagues'],
				]
			]);
		} catch (RecordNotFoundException $ex) {
			$this->Flash->info(__('Invalid event.'));
			return $this->redirect(['controller' => 'Events', 'action' => 'index']);
		} catch (InvalidPrimaryKeyException $ex) {
			$this->Flash->info(__('Invalid event.'));
			return $this->redirect(['controller' => 'Events', 'action' => 'index']);
		}
		$event->prices = collection($event->prices)->indexBy('id')->toArray();

		$this->Authorization->authorize($event);
		$this->Configuration->loadAffiliate($event->affiliate_id);

		$event_obj = $this->moduleRegistry->load("EventType:{$event->event_type->type}");
		$event->mergeAutoQuestions($event_obj, null, true);

		$query = $this->Registrations->find()
			->contain(['People', 'Payments'])
			->where(['Registrations.event_id' => $id])
			->order(['Registrations.payment' => 'DESC', 'Registrations.created' => 'DESC']);

		if ($this->request->is('csv')) {
			$query->contain([
				'People' => [
					Configure::read('Security.authModel'),
					'Groups',
					'Related' => [Configure::read('Security.authModel')],
				],
				'Payments' => ['RegistrationAudits'],
				'Responses',
			]);
			if (!empty($event->division_id) && !empty($event->division->league->sport)) {
				$sport = $event->division->league->sport;
			} else {
				$sports = Configure::read('options.sport');
				if (count($sports) == 1) {
					$sport = reset($sports);
				}
			}
			if (isset($sport)) {
				$query->contain(['People' => [
					'Skills' => [
						'queryBuilder' => function (Query $q) use ($sport) {
							return $q->where(['Skills.sport' => $sport]);
						},
					],
				]]);
			}
			$this->set('registrations', $query);
			$this->response->download("Registrations - {$event->name}.csv");
		} else {
			$this->set('registrations', $this->paginate($query));
		}

		$this->set(compact('event'));
	}

	public function summary() {
		$id = $this->request->getQuery('event');
		try {
			$event = $this->Registrations->Events->get($id, [
				'contain' => [
					'EventTypes',
					'Questionnaires' => [
						'Questions' => [
							'queryBuilder' => function (Query $q) {
								return $q->where(['Questions.active' => true, 'Questions.anonymous' => false]);
							},
							'Answers',
						],
					],
					'Divisions' => ['Leagues'],
				]
			]);
		} catch (RecordNotFoundException $ex) {
			$this->Flash->info(__('Invalid event.'));
			return $this->redirect(['controller' => 'Events', 'action' => 'index']);
		} catch (InvalidPrimaryKeyException $ex) {
			$this->Flash->info(__('Invalid event.'));
			return $this->redirect(['controller' => 'Events', 'action' => 'index']);
		}

		$this->Authorization->authorize($event);
		$this->Configuration->loadAffiliate($event->affiliate_id);

		$event_obj = $this->moduleRegistry->load("EventType:{$event->event_type->type}");
		$event->mergeAutoQuestions($event_obj, null, true);

		// If the event is all men or all women, there's no point in including this
		if ($event->open_cap != 0 && $event->women_cap != 0) {
			$gender_split = $this->Registrations->find()
				->contain('People')
				->select(['count' => 'COUNT(Registrations.id)', Configure::read('gender.column') => 'People.' . Configure::read('gender.column')])
				->where([
					'Registrations.event_id' => $id,
					'Registrations.payment !=' => 'Cancelled',
				])
				->group(['People.' . Configure::read('gender.column')])
				->order(['People.' . Configure::read('gender.column') => Configure::read('gender.order')])
				->toArray();

			// We need to include a gender breakdown of the payment statuses if both
			// genders are allowed to register.
			$payment = $this->Registrations->find()
				->contain('People')
				->select(['count' => 'COUNT(Registrations.payment)', Configure::read('gender.column') => 'People.' . Configure::read('gender.column'), 'payment' => 'Registrations.payment'])
				->where([
					'Registrations.event_id' => $id,
				])
				->group(['Registrations.payment', 'People.' . Configure::read('gender.column')])
				->order(['Registrations.payment', 'People.' . Configure::read('gender.column') => Configure::read('gender.order')])
				->toArray();
		} else {
			$payment = $this->Registrations->find()
				->select(['count' => 'COUNT(Registrations.payment)', 'payment' => 'Registrations.payment'])
				->where([
					'Registrations.event_id' => $id,
				])
				->group(['Registrations.payment'])
				->order(['Registrations.payment'])
				->toArray();
		}

		$responses = $this->Registrations->Responses->find()
			->select(['count' => 'COUNT(answer_id)', 'question_id' => 'question_id', 'answer_id' => 'answer_id'])
			->where([
				'event_id' => $id,
				'answer_id IS NOT' => null,
			])
			->group(['question_id', 'answer_id'])
			->order(['question_id'])
			->toArray();

		$this->set(compact('event', 'gender_split', 'payment', 'responses'));
	}

	public function statistics() {
		$this->Authorization->authorize($this);
		$year = $this->request->getQuery('year');
		if ($year === null) {
			$year = FrozenTime::now()->year;
		}

		$affiliates = $this->Authentication->applicableAffiliateIDs(true);
		$this->set(compact('affiliates'));

		$query = $this->Registrations->find();

		$events = $this->Registrations->Events->find()
			->select(['registration_count' => $query->func()->count('Registrations.id')])
			->select($this->Registrations->Events)
			->select($this->Registrations->Events->EventTypes)
			->select($this->Registrations->Events->Affiliates)
			->select($this->Registrations->Events->Divisions)
			->select($this->Registrations->Events->Divisions->Leagues)
			->matching('Affiliates', function (Query $q) use ($affiliates) {
				return $q->where(['Affiliates.id IN' => $affiliates]);
			})
			->leftJoin(['Registrations' => 'registrations'], ['Registrations.event_id = Events.id'])
			->contain(['EventTypes', 'Divisions' => ['Leagues', 'Days']])
			->where([
				'Registrations.payment !=' => 'Cancelled',
				'OR' => [
					// TODO: Use a query object here
					'YEAR(Events.open)' => $year,
					'YEAR(Events.close)' => $year,
				],
			])
			->group(['Events.id'])
			->order(['Affiliates.name', 'Events.event_type_id', 'Events.open' => 'DESC', 'Events.close' => 'DESC', 'Events.id'])
			->toArray();

		$years = $this->Registrations->Events->find()
			->hydrate(false)
			// TODO: Use a query object here
			->select(['year' => 'YEAR(open)'])
			->distinct(['year' => 'YEAR(open)'])
			->matching('Affiliates', function (Query $q) use ($affiliates) {
				return $q->where(['Affiliates.id IN' => $affiliates]);
			})
			->order(['year'])
			->toArray();

		$this->set(compact('events', 'years'));
	}

	public function report() {
		$this->Authorization->authorize($this);
		if ($this->request->is('post')) {
			// Deconstruct dates
			$start_date = sprintf('%04d-%02d-%02d', $this->request->data['start_date']['year'], $this->request->data['start_date']['month'], $this->request->data['start_date']['day']);
			$end_date = sprintf('%04d-%02d-%02d', $this->request->data['end_date']['year'], $this->request->data['end_date']['month'], $this->request->data['end_date']['day']);
		} else {
			$start_date = $this->request->getQuery('start_date');
			$end_date = $this->request->getQuery('end_date');
			if (!$start_date || !$end_date) {
				// Just return, which will present the user with a date selection
				return;
			}
		}

		if ($start_date > $end_date) {
			$this->Flash->info(__('Start date must be before end date!'));
			return;
		}

		$affiliate = $this->request->getQuery('affiliate');
		$affiliates = $this->Authentication->applicableAffiliateIDs(true);

		$query = $this->Registrations->find()
			->contain([
				'Events' => ['EventTypes', 'Affiliates'],
				'Prices',
				'Payments' => ['RegistrationAudits'],
				'People',
			])
			->where([
				function (QueryExpression $exp) use ($start_date, $end_date) {
					return $exp->between('Registrations.created', $start_date, "{$end_date} 23:59:59", 'date');
				},
				'Events.affiliate_id IN' => $affiliates,
			]);

		if ($this->request->is('csv')) {
			$query
				->contain([
					'People' => [
						Configure::read('Security.authModel'),
						'Related' => [Configure::read('Security.authModel')],
					],
				])
				->order(['Events.affiliate_id', 'Registrations.payment' => 'DESC', 'Registrations.created']);
			$this->set('registrations', $query);
			$this->response->download("Registrations $start_date to $end_date.csv");
		} else {
			$query->order(['Events.affiliate_id']);
			$this->paginate = [
				'order' => ['Registrations.payment' => 'DESC'],
			];
			$this->set('registrations', $this->paginate($query));
		}

		$this->set(compact('affiliates', 'affiliate', 'start_date', 'end_date'));
	}

	public function TODOLATER_accounting() {
		$this->Authorization->authorize($this);
	}

	/**
	 * View method
	 *
	 * @return void|\Cake\Network\Response
	 */
	public function view() {
		$id = $this->request->getQuery('registration');
		try {
			$registration = $this->Registrations->get($id, [
				'contain' => [
					'People',
					'Events' => [
						'EventTypes',
						'Questionnaires' => [
							'Questions' => [
								'queryBuilder' => function (Query $q) {
									return $q->where(['Questions.active' => true, 'Questions.anonymous' => false]);
								},
								'Answers',
							],
						],
						'Divisions' => ['Leagues'],
					],
					'Responses',
					'Payments' => [
						'queryBuilder' => function (Query $q) {
							return $q->order(['Payments.id']);
						},
						'RegistrationAudits',
					],
				]
			]);
		} catch (RecordNotFoundException $ex) {
			$this->Flash->info(__('Invalid registration.'));
			return $this->redirect(['controller' => 'Events', 'action' => 'index']);
		} catch (InvalidPrimaryKeyException $ex) {
			$this->Flash->info(__('Invalid registration.'));
			return $this->redirect(['controller' => 'Events', 'action' => 'index']);
		}

		$this->Authorization->authorize($registration);
		$this->Configuration->loadAffiliate($registration->event->affiliate_id);

		$event_obj = $this->moduleRegistry->load("EventType:{$registration->event->event_type->type}");
		$registration->event->mergeAutoQuestions($event_obj, $registration->person->id, true);

		$this->set(compact('registration'));
	}

	/**
	 * Register method
	 *
	 * @return void|\Cake\Network\Response Redirects on successful add, renders view otherwise.
	 */
	public function register() {
		$this->Registrations->expireReservations();

		$id = $this->request->getQuery('event');
		try {
			$event = $this->Registrations->Events->get($id, [
				'contain' => [
					'EventTypes',
					'Prices' => [
						'queryBuilder' => function (Query $q) {
							return $q->where(['Prices.open', 'Prices.close', 'Prices.id']);
						},
					],
					'Questionnaires' => [
						'Questions' => [
							'queryBuilder' => function (Query $q) {
								return $q->where(['active' => true]);
							},
							'Answers' => [
								'queryBuilder' => function (Query $q) {
									return $q->where(['active' => true]);
								},
							],
						],
					],
					'Divisions' => ['Leagues'],
				]
			]);
		} catch (RecordNotFoundException $ex) {
			$this->Flash->info(__('Invalid event.'));
			return $this->redirect(['controller' => 'Events', 'action' => 'wizard']);
		} catch (InvalidPrimaryKeyException $ex) {
			$this->Flash->info(__('Invalid event.'));
			return $this->redirect(['controller' => 'Events', 'action' => 'wizard']);
		}

		// TODO: Eliminate the 'option' option once all old links are gone
		$price_id = $this->request->getQuery('variant') ?: $this->request->getQuery('option');
		if (empty($price_id) && $this->request->is(['patch', 'post', 'put'])) {
			$price_id = $this->request->data['price_id'];
		}
		if (!empty($price_id)) {
			$price = collection($event->prices)->firstMatch(['id' => $price_id]);
			if (empty($price)) {
				$this->Flash->info(__('Invalid price point.'));
				return $this->redirect(['controller' => 'Events', 'action' => 'wizard']);
			}
		} else if (count($event->prices) == 1) {
			$price = $event->prices[0];
		} else {
			$price = null;
		}

		$context = new ContextResource($event, ['price' => $price, 'waiting' => $this->request->getQuery('waiting'), 'all_rules' => true]);
		$this->Authorization->authorize($context);
		$this->Configuration->loadAffiliate($event->affiliate_id);

		$event_obj = $this->moduleRegistry->load("EventType:{$event->event_type->type}");
		$event->mergeAutoQuestions($event_obj, $this->UserCache->currentId());

		$registration = $this->Registrations->newEntity();
		$force_save = false;
		if (isset($price)) {
			if (empty($event->questionnaire->questions) && !in_array($price->online_payment_option, [ONLINE_MINIMUM_DEPOSIT, ONLINE_SPECIFIC_DEPOSIT, ONLINE_NO_MINIMUM])) {
				// The event has no questionnaire, and no price options; save trivial registration data and proceed
				$force_save = true;
				if (!$price->allow_deposit) {
					$this->request->data['payment_amount'] = $price->total;
				} else {
					$this->request->data['payment_amount'] = $price->minimum_deposit;
				}
				$this->request->data['event_id'] = $id;
			}

			// We have a price selected, set it in the entity so the view reflects it
			$registration->price = $price;
			$registration->price_id = $price->id;
		}

		// Data was posted, save it and proceed
		if ($this->request->is(['patch', 'post', 'put']) || $force_save) {
			$responseValidator = $this->Registrations->Responses->validationDefault(new Validator());
			if (!empty($event->questionnaire->questions)) {
				$responseValidator = $event->questionnaire->addResponseValidation($responseValidator, $event_obj, $this->request->data['responses'], $event);
			}

			$registration = $this->Registrations->patchEntity($registration, $this->request->data, ['associated' => [
				'Responses' => ['validate' => $responseValidator],
			]]);
			$this->_reindexResponses($registration, $event);

			if (!$this->Registrations->save($registration, compact('event', 'event_obj'))) {
				$this->Flash->warning(__('The registration could not be saved. Please correct the errors below and try again.'));
			} else if ($registration->payment == 'Waiting') {
				$this->Flash->success(__('You have been added to the waiting list for this event.'));
				return $this->redirect(['controller' => 'Events', 'action' => 'wizard']);
			} else if ($price->total == 0) {
				if (empty($registration->responses)) {
					$this->Flash->success(__('Your registration for this event has been confirmed.'));
				} else {
					$this->Flash->success(__('Your preferences have been saved and your registration confirmed.'));
				}
				return $this->redirect(['controller' => 'Events', 'action' => 'wizard']);
			} else {
				if (empty($registration->responses)) {
					$this->Flash->success(__('Your registration for this event has been saved. Please complete your payment to confirm your registration.'));
				} else {
					$this->Flash->success(__('Your preferences for this registration have been saved. Please complete your payment to confirm your registration.'));
				}
				return $this->redirect(['action' => 'checkout']);
			}
		}

		$this->set(compact('id', 'event', 'price_id', 'event_obj', 'registration'));
		$this->set('waiting', $context->waiting);
	}

	public function register_payment_fields() {
		$this->Authorization->authorize($this);
		$this->request->allowMethod('ajax');

		$price_id = $this->request->data['price_id'];
		if (!empty($price_id)) {
			$contain = ['Events' => ['EventTypes']];
			$registration = $this->request->getQuery('registration_id');
			if ($registration) {
				$contain['Events']['Registrations'] = [
					'queryBuilder' => function (Query $q) use ($registration) {
						return $q->where(['Registrations.id' => $registration]);
					},
				];
			}

			try {
				$price = $this->Registrations->Prices->get($price_id, compact('contain'));
				$for_edit = $this->request->getQuery('for_edit');

				// This authorization call is just to set the message, if any, in the price
				$this->Authorization->can(new ContextResource($price->event, [
					'person_id' => $for_edit ? $price->event->registrations[0]->person_id : $this->UserCache->currentId(),
					'price' => $price,
					'for_edit' => $for_edit ? $price->event->registrations[0] : false,
					'waiting' => $this->request->getQuery('waiting'),
					'ignore_date' => true,
				]), 'register');

				$this->set(compact('price', 'for_edit'));
			} catch (RecordNotFoundException $ex) {
			} catch (InvalidPrimaryKeyException $ex) {
			}
		}
	}

	public function redeem() {
		$id = $this->request->getQuery('registration');
		try {
			$registration = $this->Registrations->get($id, [
				'contain' => [
					'People' => [
						'Credits' => [
							'queryBuilder' => function (Query $q) {
								return $q->where(['amount != amount_used']);
							},
						],
					],
					'Events' => [
						'EventTypes',
						'Prices',
						'Divisions' => ['Leagues'],
					],
					'Prices',
					'Responses',
					'Payments',
				]
			]);
		} catch (RecordNotFoundException $ex) {
			$this->Flash->info(__('Invalid registration.'));
			return $this->redirect(['controller' => 'Events', 'action' => 'wizard']);
		} catch (InvalidPrimaryKeyException $ex) {
			$this->Flash->info(__('Invalid registration.'));
			return $this->redirect(['controller' => 'Events', 'action' => 'wizard']);
		}

		$registration->person->credits = collection($registration->person->credits)->match(['affiliate_id' => $registration->event->affiliate_id])->toArray();

		$this->Authorization->authorize(new ContextResource($registration, [
			'person' => $registration->person,
			'price' => $registration->price,
			'event' => $registration->event,
			'prices' => $registration->event->prices,
		]));
		$this->Configuration->loadAffiliate($registration->event->affiliate_id);

		$credit = $this->request->getQuery('credit');
		if ($credit) {
			$credit = collection($registration->person->credits)->firstMatch(['id' => $credit]);
			if (!$credit) {
				$this->Flash->info(__('Invalid credit.'));
				return $this->redirect(['action' => 'checkout']);
			}
		}

		$payment = $this->Registrations->Payments->newEntity();

		if ($credit) {
			$payment = $this->Registrations->Payments->patchEntity($payment, [
				'payment_type' => ($credit->balance >= $registration->balance ? ($registration->total_payment == 0 ? 'Full' : 'Remaining Balance') : 'Installment'),
				'payment_amount' => min($credit->balance, $registration->balance),
				'payment_method' => 'Credit Redeemed',
				'notes' => "Applied credit #{$credit->id}",
			]);
			$registration->payments[] = $payment;
			$registration->dirty('payments', true);

			if (!empty($credit->notes)) {
				$credit->notes .= "\n";
			}
			$credit->notes .= __('{0} applied to registration #{1}: {2}',
				$credit->amount_used == 0 && $payment->payment_amount == $credit->amount ? __('Credit') : Number::currency($payment->payment_amount),
				$registration->id, $registration->event->name);
			$credit->amount_used += $payment->payment_amount;

			// We don't actually want to update the "modified" column in the people table here, but we do need to save the credit
			if ($this->Registrations->People->hasBehavior('Timestamp')) {
				$this->Registrations->People->removeBehavior('Timestamp');
			}
			$registration->dirty('person', true);
			$registration->person->dirty('credits', true);

			if ($this->Registrations->save($registration, ['registration' => $registration, 'event' => $registration->event])) {
				$this->Flash->success(__('The credit has been applied to the chosen registration.'));
				$this->UserCache->clear('Credits', $registration->person_id);
			} else {
				$this->Flash->info(__('There was an error redeeming the credit.'));
			}
			return $this->redirect(['action' => 'checkout']);
		}

		$this->set(compact('registration'));
	}

	public function checkout() {
		$this->Registrations->expireReservations();
		$this->Authorization->authorize($this);

		$registrations = $this->Registrations->find()
			->contain([
				'Events' => ['EventTypes', 'Prices'],
				'Prices',
				'Payments',
				'Responses',
			])
			->where([
				'Registrations.person_id' => $this->UserCache->currentId(),
				'Registrations.payment IN' => Configure::read('registration_unpaid'),
			])
			->toArray();

		// If there are no unpaid registrations, then we probably got here by
		// unregistering from our last thing. In that case, we don't want to
		// disturb the flash message, just go back to the event list.
		if (empty($registrations)) {
			return $this->redirect(['controller' => 'Events', 'action' => 'wizard']);
		}

		$person = $this->Registrations->People->get($this->UserCache->currentId(), [
			'contain' => [
				Configure::read('Security.authModel'),
				'Credits' => [
					'queryBuilder' => function (Query $q) {
						return $q->where(['Credits.amount_used < Credits.amount']);
					},
				],
				// TODOLATER: Include relatives, and allow us to pay for them too; see also All/splash.ctp
				'Related' => [Configure::read('Security.authModel')],
			]
		]);

		$other = [];
		$affiliate = $this->request->getQuery('affiliate');
		foreach ($registrations as $key => $registration) {
			// Check that we're still allowed to pay for this
			if (!$registration->price->allow_late_payment && $registration->price->close->isPast()) {
				$other_prices = collection($registration->event->prices)->filter(function ($price) {
					return $price->close->isFuture();
				})->toArray();
				$prereg = collection($this->UserCache->read('Preregistrations'))->firstMatch(['event_id' => $registration->event_id]);
				if (!empty($other_prices) || empty($prereg)) {
					$other[] = ['registration' => $registration, 'reason' => __('Payment deadline has passed'), 'change_price' => !empty($other_prices)];
					unset($registrations[$key]);
					continue;
				}
			}

			// Find the registration cap and how many are already registered.
			$cap = $registration->event->cap($person->roster_designation);
			if ($cap != CAP_UNLIMITED) {
				$paid = $registration->event->count($person->roster_designation, ['Registrations.id !=' => $registration->id]);
				if ($cap <= $paid) {
					$other[] = ['registration' => $registration, 'reason' => __('You are on the waiting list')];
					unset($registrations[$key]);
					continue;
				}
			}

			// Don't allow the user to pay for things from multiple affiliates at the same time
			if (!$affiliate) {
				$affiliate = $registration->event->affiliate_id;
			} else if ($affiliate != $registration->event->affiliate_id) {
				$other[] = ['registration' => $registration, 'reason' => __('In a different affiliate')];
				unset($registrations[$key]);
				continue;
			}

			// Don't allow further payment on "deposit only" items
			if ($registration->price->deposit_only && in_array($registration->payment, Configure::read('registration_some_paid'))) {
				$other[] = ['registration' => $registration, 'reason' => __('Deposit paid; balance must be paid off-line')];
				unset($registrations[$key]);
				continue;
			}

			// Don't allow any payment on $0 "deposit only" items
			if ($registration->price->online_payment_option == ONLINE_NO_PAYMENT) {
				$other[] = ['registration' => $registration, 'reason' => __('Registration for this is open, but online payments are not allowed')];
				unset($registrations[$key]);
				continue;
			}

			// Set the description for the invoice
			$event_obj = $this->moduleRegistry->load("EventType:{$registration->event->event_type->type}");
			$registration->event->payment_desc = $event_obj->longDescription($registration);
		}

		$this->Configuration->loadAffiliate($affiliate);
		$person->credits = collection($person->credits)->match(['affiliate_id' => $affiliate])->toArray();

		if (Configure::read('registration.online_payments')) {
			$payment_obj = $this->moduleRegistry->load('Payment:' . Configure::read('payment.payment_implementation'));
		}

		// Forms will use $registrations[0], but that may have been unset above.
		$registrations = array_values($registrations);
		$this->set(compact('registrations', 'other', 'person', 'payment_obj'));
	}

	public function unregister() {
		$this->request->allowMethod(['get', 'post', 'delete']);

		try {
			$registration = $this->Registrations->get($this->request->getQuery('registration'), [
				'contain' => [
					'Events' => ['EventTypes'],
					'Prices',
					'Responses',
				]
			]);
		} catch (RecordNotFoundException $ex) {
			$this->Flash->info(__('Invalid registration.'));
			return $this->redirect(['action' => 'checkout']);
		} catch (InvalidPrimaryKeyException $ex) {
			$this->Flash->info(__('Invalid registration.'));
			return $this->redirect(['action' => 'checkout']);
		}

		$this->Authorization->authorize($registration);
		$this->Configuration->loadAffiliate($registration->event->affiliate_id);

		if ($this->Registrations->delete($registration)) {
			$this->Flash->success(__('Successfully unregistered from this event.'));
		} else {
			$this->Flash->warning(__('Failed to unregister from this event!'));
		}

		if ($this->Authentication->getIdentity()->isMe($registration)) {
			return $this->redirect(['action' => 'checkout']);
		} else {
			return $this->redirect('/');
		}
	}

	public function payment() {
		return $this->_payment();
	}

	private function _payment($checkHash = true) {
		if (Configure::read('payment.popup')) {
			$this->viewBuilder()->layout('bare');
		}
		$payment_obj = $this->moduleRegistry->load('Payment:' . Configure::read('payment.payment_implementation'));
		list($result, $audit, $registration_ids) = $payment_obj->process($this->request, $checkHash);
		if ($result) {
			$errors = [];

			$registrations = $this->Registrations->find()
				->contain([
					'People',
					'Events' => [
						'EventTypes',
						'Divisions' => ['Leagues'],
					],
					'Prices',
					'Payments',
					'Responses',
				])
				->where(['Registrations.id IN' => $registration_ids])
				->toArray();
			$this->Configuration->loadAffiliate($registrations[0]->event->affiliate_id);

			// We need another copy of the registrations, to send to the invoice page,
			// so that it will display registration state as it stood before the payment.
			// TODO: Maybe change the invoice page instead?
			$registrations_original = $this->Registrations->find()
				->contain([
					'People',
					'Events' => [
						'EventTypes',
						'Divisions' => ['Leagues'],
					],
					'Prices',
					'Payments',
					'Responses',
				])
				->where(['Registrations.id IN' => $registration_ids])
				->toArray();

			$audit = $this->Registrations->Payments->RegistrationAudits->newEntity($audit);
			if (!$this->Registrations->Payments->RegistrationAudits->save($audit)) {
				$errors[] = __('There was an error updating the audit record in the database. Contact the office to ensure that your information is updated, quoting order #<b>{0}</b>, or you may not be allowed to be added to rosters, etc.', $audit->order_id);
				$this->log($audit->errors());
			}

			foreach ($registrations as $key => $registration) {
				list ($cost, $tax1, $tax2) = $registration->paymentAmounts();
				$registration->payments[] = $this->Registrations->Payments->newEntity([
					'registration_audit_id' => $audit->id,
					'payment_method' => 'Online',
					'payment_amount' => $cost + $tax1 + $tax2,
				], ['validate' => 'payment', 'registration' => $registration]);
				$registration->dirty('payments', true);

				// The registration is also passed as an option, so that the payment rules have easy access to it
				if (!$this->Registrations->save($registration, ['registration' => $registration, 'event' => $registration->event])) {
					$errors[] = __('Your payment was approved, but there was an error updating your payment status in the database. Contact the office to ensure that your information is updated, quoting order #<b>{0}</b>, or you may not be allowed to be added to rosters, etc.', $audit->order_id);
				}
			}
		} else {
			$registrations_original = [];
		}
		$this->set(array_merge(compact('result', 'audit', 'errors'), ['registrations' => $registrations_original]));
	}

	public function payment_from_email() {
		$this->Authorization->authorize($this);
		if (!empty($this->request->data)) {
			$payment_obj = $this->moduleRegistry->load('Payment:' . Configure::read('payment.payment_implementation'));
			$values = $payment_obj->parseEmail($this->request->data['email_text']);
			if (!$values) {
				return;
			}

			list($result, $audit, $registration_ids) = $payment_obj->processData($values, false);
			if (!$result) {
				$this->Flash->warning(__('Unable to extract payment information from the text provided.'));
				return;
			}

			// Check that the registrations aren't already marked as paid
			$registrations = $this->Registrations->find()
				->contain(['Payments' => ['RegistrationAudits']])
				->where(['Registrations.id IN' => $registration_ids]);
			if ($registrations->count() != count($registration_ids)) {
				$this->Flash->warning(__('A registration in this email could not be loaded.'));
				return;
			}
			if ($registrations->some(function (Registration $registration) {
				if ($registration->payment == 'Paid') {
					return !empty($registration->payments);
				}
			})) {
				$this->Flash->warning(__('A registration in this email has already been marked as paid. All registrations must be unpaid before this can proceed.'));
				return;
			}

			$this->set(['fields' => $values]);
		}
	}

	public function payment_from_email_confirmation() {
		$this->Authorization->authorize($this);
		$this->viewBuilder()->template('payment');
		return $this->_payment(false);
	}

	public function add_payment() {
		$id = $this->request->getQuery('registration');
		try {
			$registration = $this->Registrations->get($id, [
				'contain' => [
					'People' => [
						'Credits' => [
							'queryBuilder' => function (Query $q) {
								return $q->where(['amount != amount_used']);
							},
						],
					],
					'Events' => [
						'EventTypes',
						'Divisions' => ['Leagues'],
					],
					'Responses',
					'Payments',
				]
			]);
		} catch (RecordNotFoundException $ex) {
			$this->Flash->info(__('Invalid registration.'));
			return $this->redirect('/');
		} catch (InvalidPrimaryKeyException $ex) {
			$this->Flash->info(__('Invalid registration.'));
			return $this->redirect('/');
		}

		$this->Authorization->authorize($registration);
		$this->Configuration->loadAffiliate($registration->event->affiliate_id);
		$payment = $this->Registrations->Payments->newEntity();

		$this->set(compact('registration', 'payment'));

		if ($this->request->is(['patch', 'post', 'put'])) {
			// Handle credit redemption
			if (array_key_exists('credit_id', $this->request->data)) {
				$credit = collection($registration->person->credits)->firstMatch(['id' => $this->request->data['credit_id']]);
				if (!$credit) {
					$this->Flash->info(__('Invalid credit.'));
					return;
				}

				$this->request->data['payment_amount'] = min($this->request->data['payment_amount'], $registration->balance, $credit->balance);
				$this->request->data['notes'] = __('Applied {0} from credit #{1}', Number::currency($this->request->data['payment_amount']), $credit->id);

				$credit->amount_used += $this->request->data['payment_amount'];
				if (!empty($credit->notes)) {
					$credit->notes .= "\n";
				}
				$credit->notes .= __('{0} applied to registration #{1}: {2}',
					$this->request->data['payment_amount'] == $credit->amount ? __('Credit') : Number::currency($this->request->data['payment_amount']),
					$registration->id, $registration->event->name);

				// We don't actually want to update the "modified" column in the people table here, but we do need to save the credit
				if ($this->Registrations->People->hasBehavior('Timestamp')) {
					$this->Registrations->People->removeBehavior('Timestamp');
				}
				$registration->dirty('person', true);
				$registration->person->dirty('credits', true);
			}

			// The registration is also passed as an option, so that the payment marshaller has easy access to it
			$payment = $this->Registrations->Payments->patchEntity($payment, $this->request->data, ['validate' => 'payment', 'registration' => $registration]);
			$registration->payments[] = $payment;
			$registration->dirty('payments', true);

			// The registration is also passed as an option, so that the payment rules have easy access to it
			if ($this->Registrations->save($registration, ['registration' => $registration, 'event' => $registration->event])) {
				$this->Flash->success(__('The payment has been saved.'));
				return $this->redirect(['action' => 'view', 'registration' => $registration->id]);
			} else {
				$this->Flash->warning(__('The payment could not be saved. Please correct the errors below and try again.'));
			}
		}
	}

	public function refund_payment() {
		$id = $this->request->getQuery('payment');
		try {
			$registration_id = $this->Registrations->Payments->field('registration_id', compact('id'));
			$registration = $this->Registrations->get($registration_id, [
				'contain' => [
					'People',
					'Events' => [
						'EventTypes',
						'Divisions' => ['Leagues'],
					],
					'Responses',
					'Payments',
				]
			]);

			$payment = collection($registration->payments)->firstMatch(compact('id'));
		} catch (RecordNotFoundException $ex) {
			$this->Flash->info(__('Invalid payment.'));
			return $this->redirect('/');
		} catch (InvalidPrimaryKeyException $ex) {
			$this->Flash->info(__('Invalid payment.'));
			return $this->redirect('/');
		}

		$this->Authorization->authorize($payment);
		$this->Configuration->loadAffiliate($registration->event->affiliate_id);
		$refund = $this->Registrations->Payments->newEntity();

		if (Configure::read('registration.online_payments')) {
			$payment_obj = $this->moduleRegistry->load('Payment:' . Configure::read('payment.payment_implementation'));
		} else {
			$payment_obj = null;
		}
		$this->set(compact('registration', 'payment', 'refund', 'payment_obj'));

		if ($this->request->is(['patch', 'post', 'put'])) {
			// The form has a positive amount to be refunded, which needs to be retained in case of an error.
			// But the refund record must have a negative amount.
			$refund_data = $this->request->data;
			$refund_data['payment_amount'] *= -1;

			$registration->mark_refunded = $this->request->data['mark_refunded'];

			// The registration is also passed as an option, so that the payment marshaller has easy access to it
			$refund = $this->Registrations->Payments->patchEntity($refund, $refund_data, ['validate' => 'refund', 'registration' => $registration]);
			$registration->payments[] = $refund;
			$registration->dirty('payments', true);

			$payment->refunded_amount = round($payment->refunded_amount + $this->request->data['payment_amount'], 2);

			if ($this->Registrations->connection()->transactional(function () use ($registration, $payment_obj, $payment) {
				// The registration is also passed as an option, so that the payment rules have easy access to it
				if (!$this->Registrations->save($registration, ['registration' => $registration, 'event' => $registration->event])) {
					$this->Flash->warning(__('The refund could not be saved. Please correct the errors below and try again.'));
					return false;
				}

				if ($payment_obj && $payment_obj->can_refund && $this->request->data['online_refund']) {
					if (!$payment_obj->refund($payment, $this->request->data['payment_amount'])) {
						$this->Flash->error(__('Failed to issue refund through online processor. Refund data was NOT saved. You can try again, or uncheck the "Issue refund through online payment provider" box and issue the refund manually.'));
						return false;
					}
				}

				return true;
			})) {
				$this->Flash->success(__('The refund has been saved.'));
				return $this->redirect(['action' => 'view', 'registration' => $registration->id]);
			}
		}
	}

	public function credit_payment() {
		$id = $this->request->getQuery('payment');
		try {
			$registration_id = $this->Registrations->Payments->field('registration_id', compact('id'));
			$registration = $this->Registrations->get($registration_id, [
				'contain' => [
					'People' => ['Credits'],
					'Events' => [
						'EventTypes',
						'Divisions' => ['Leagues'],
					],
					'Responses',
					'Payments',
				]
			]);

			$payment = collection($registration->payments)->firstMatch(compact('id'));
		} catch (RecordNotFoundException $ex) {
			$this->Flash->info(__('Invalid payment.'));
			return $this->redirect('/');
		} catch (InvalidPrimaryKeyException $ex) {
			$this->Flash->info(__('Invalid payment.'));
			return $this->redirect('/');
		}

		$this->Authorization->authorize($payment);
		$this->Configuration->loadAffiliate($registration->event->affiliate_id);
		$refund = $this->Registrations->Payments->newEntity();

		if ($this->request->is(['patch', 'post', 'put'])) {
			// The form has a positive amount to be credited, which needs to be retained in case of an error.
			// But the credit record must have a negative amount.
			$refund_data = $this->request->data;
			$refund_data['payment_amount'] *= -1;

			$registration->mark_refunded = $this->request->data['mark_refunded'];

			// The registration is also passed as an option, so that the payment marshaller has easy access to it
			$refund = $this->Registrations->Payments->patchEntity($refund, $refund_data, ['validate' => 'credit', 'registration' => $registration]);
			$registration->payments[] = $refund;
			$registration->dirty('payments', true);

			$payment->refunded_amount = round($payment->refunded_amount + $this->request->data['payment_amount'], 2);

			$credit = $this->Registrations->People->Credits->newEntity([
				'affiliate_id' => $registration->event->affiliate_id,
				'amount' => $this->request->data['payment_amount'],
				'notes' => $this->request->data['credit_notes'],
			]);
			$registration->person->credits[] = $credit;

			// We don't actually want to update the "modified" column in the people table here, but we do need to save the credit
			if ($this->Registrations->People->hasBehavior('Timestamp')) {
				$this->Registrations->People->removeBehavior('Timestamp');
			}
			$registration->dirty('person', true);
			$registration->person->dirty('credits', true);

			if ($this->Registrations->save($registration, ['registration' => $registration, 'event' => $registration->event])) {
				$this->Flash->success(__('The credit has been saved.'));
				$this->UserCache->clear('Credits', $registration->person_id);
				return $this->redirect(['action' => 'view', 'registration' => $registration->id]);
			}
			$this->Flash->warning(__('The credit could not be saved. Please correct the errors below and try again.'));
		}

		$this->set(compact('registration', 'payment', 'credit'));
	}

	public function transfer_payment() {
		$id = $this->request->getQuery('payment');
		try {
			$registration_id = $this->Registrations->Payments->field('registration_id', compact('id'));
			$registration = $this->Registrations->get($registration_id, [
				'contain' => [
					'People' => ['Groups'],
					'Events' => [
						'EventTypes',
						'Divisions' => ['Leagues'],
					],
					'Responses',
					'Payments',
				]
			]);

			$payment = collection($registration->payments)->firstMatch(compact('id'));
		} catch (RecordNotFoundException $ex) {
			$this->Flash->info(__('Invalid payment.'));
			return $this->redirect('/');
		} catch (InvalidPrimaryKeyException $ex) {
			$this->Flash->info(__('Invalid payment.'));
			return $this->redirect('/');
		}

		$this->Authorization->authorize($payment);
		$this->Configuration->loadAffiliate($registration->event->affiliate_id);
		$refund = $this->Registrations->Payments->newEntity();

		$unpaid = $this->UserCache->read('RegistrationsUnpaid', $registration->person_id);
		foreach ($this->UserCache->read('RelativeIDs', $registration->person_id) as $relative_id) {
			$unpaid = array_merge($unpaid, $this->UserCache->read('RegistrationsUnpaid', $relative_id));
		}
		if ($this->_isChild($registration->person)) {
			// Check everyone related to this child's adult relatives. Assumption is that those
			// people will be their siblings, which is a common transfer target.
			$relations = $this->UserCache->read('RelatedTo', $registration->person_id);
			foreach ($relations as $relation) {
				$this->Registrations->People->loadInto($relation, ['Groups']);
				if (!$this->_isChild($relation)) {
					foreach ($this->UserCache->read('RelativeIDs', $relation->id) as $relative_id) {
						if ($relative_id != $registration->person_id) {
							$unpaid = array_merge($unpaid, $this->UserCache->read('RegistrationsUnpaid', $relative_id));
						}
					}
				}
			}
		}

		$unpaid = collection($unpaid)->filter(function ($unpaid) use ($registration) {
			return $unpaid->event->affiliate_id == $registration->event->affiliate_id;
		})->toArray();
		if (empty($unpaid)) {
			$this->Flash->info(__('This user has no unpaid registrations to transfer the payment to.'));
			return $this->redirect('/');
		}

		if ($this->request->is(['patch', 'post', 'put'])) {
			try {
				if (!collection($unpaid)->firstMatch(['id' => $this->request->data['transfer_to_registration_id']])) {
					throw new RecordNotFoundException('Registration is not a valid transfer target.');
				}
				$to_registration = $this->Registrations->get($this->request->data['transfer_to_registration_id'], [
					'contain' => [
						'People',
						'Events' => [
							'EventTypes',
							'Divisions' => ['Leagues'],
						],
						'Responses',
						'Payments',
					]
				]);
			} catch (RecordNotFoundException $ex) {
				$this->Flash->info(__('Invalid registration.'));
				return $this->redirect(['action' => 'view', 'registration' => $registration->id]);
			} catch (InvalidPrimaryKeyException $ex) {
				$this->Flash->info(__('Invalid registration.'));
				return $this->redirect(['action' => 'view', 'registration' => $registration->id]);
			}

			if (!in_array($to_registration->payment, Configure::read('registration_unpaid'))) {
				$this->Flash->info(__('This registration is marked as {0}.', __($to_registration->payment)));
				return $this->redirect(['action' => 'view', 'registration' => $registration->id]);
			}

			$this->request->data['payment_amount'] = min($this->request->data['payment_amount'], $payment->paid, $to_registration->balance);

			// The form has a positive amount to be transferred, which needs to be retained in case of an error.
			// But the transfer record must have a negative amount.
			$refund_data = $this->request->data;
			$refund_data['payment_amount'] *= -1;

			$registration->mark_refunded = $this->request->data['mark_refunded'];

			// The registration is also passed as an option, so that the payment marshaller has easy access to it
			$refund = $this->Registrations->Payments->patchEntity($refund, $refund_data, ['validate' => 'transferFrom', 'registration' => $registration]);
			$registration->payments[] = $refund;
			$registration->dirty('payments', true);

			$payment->refunded_amount = round($payment->refunded_amount + $this->request->data['payment_amount'], 2);

			$transfer = $this->Registrations->Payments->newEntity([
				'payment_type' => 'Transfer',
				'payment_method' => 'Other',
				'payment_amount' => $this->request->data['payment_amount'],
				'notes' => $this->request->data['transfer_to_notes'],
			], ['validate' => 'transferTo', 'registration' => $to_registration]);
			$to_registration->payments[] = $transfer;
			$to_registration->dirty('payments', true);

			if ($this->Registrations->connection()->transactional(function () use ($registration, $to_registration) {
				return $this->Registrations->save($registration, ['registration' => $registration, 'event' => $registration->event]) &&
					$this->Registrations->save($to_registration, ['registration' => $to_registration, 'event' => $to_registration->event]);
			})) {
				$this->Flash->success(__('Transferred {0}', Number::currency($this->request->data['payment_amount'])));

				// Which registration we redirect to from here depends on how much was transferred
				if ($payment['refunded_amount'] < $payment['payment_amount']) {
					// There is still unrefunded money on the old registration, go back there
					return $this->redirect(['action' => 'view', 'registration' => $registration->id]);
				} else {
					// Go to the registration the money was just transferred to
					return $this->redirect(['action' => 'view', 'registration' => $to_registration->id]);
				}
			}
			$this->Flash->warning(__('The transfer could not be saved. Please correct the errors below and try again.'));
		}

		$this->set(compact('registration', 'payment', 'unpaid'));
	}

	/**
	 * Edit method
	 *
	 * @return void|\Cake\Network\Response Redirects on successful edit, renders view otherwise.
	 */
	public function edit() {
		$id = $this->request->getQuery('registration');
		try {
			$registration = $this->Registrations->get($id, [
				'contain' => [
					'People',
					'Events' => [
						'EventTypes',
						'Prices',
						'Questionnaires' => [
							'Questions' => [
								'queryBuilder' => function (Query $q) {
									return $q->where(['Questions.active' => true, 'Questions.anonymous' => false]);
								},
								'Answers' => [
									'queryBuilder' => function (Query $q) {
										return $q->where(['Answers.active' => true]);
									},
								],
							],
						],
						'Divisions' => ['Leagues'],
					],
					'Prices',
					'Responses',
				]
			]);
		} catch (RecordNotFoundException $ex) {
			$this->Flash->info(__('Invalid registration.'));
			return $this->redirect('/');
		} catch (InvalidPrimaryKeyException $ex) {
			$this->Flash->info(__('Invalid registration.'));
			return $this->redirect('/');
		}

		$this->Authorization->authorize($registration);
		$this->Configuration->loadAffiliate($registration->event['affiliate_id']);

		$event_obj = $this->moduleRegistry->load("EventType:{$registration->event->event_type->type}");
		$registration->event->mergeAutoQuestions($event_obj, $registration->person->id);

		if ($this->request->is(['patch', 'post', 'put'])) {
			$this->Authorization->can(new ContextResource($registration->event, ['for_edit' => $registration, 'all_rules' => true]), 'register');
			$responseValidator = $this->Registrations->Responses->validationDefault(new Validator());
			if (!empty($registration->event->questionnaire->questions)) {
				$responseValidator = $registration->event->questionnaire->addResponseValidation($responseValidator, $event_obj, $this->request->data['responses'], $registration->event, $registration);
			}

			// We use the "replace" saving strategy for responses, so that unnecessary responses get discarded,
			// but we need to keep a couple of things that the system generates.
			$preserve = EventTypeBase::extractAnswers($registration->responses, [
				'team_id' => TEAM_ID_CREATED,
				'franchise_id' => FRANCHISE_ID_CREATED,
			]);

			$registration = $this->Registrations->patchEntity($registration, $this->request->data, ['associated' => [
				'Responses' => ['validate' => $responseValidator],
			]]);
			$this->_reindexResponses($registration, $registration->event);
			if (!$registration->errors) {
				// TODO: Seems that the marshaller won't update $registration->price, even though
				// $registration->price_id gets set. Because it's a BelongsTo relationship, perhaps?
				// But we need it set correctly in RegistrationsTable::beforeSave. We'll do it manually. :-(
				$registration->price = collection($registration->event->prices)->firstMatch(['id' => $registration->price_id]);
				$registration->dirty('price', false);

				if (!empty($preserve['team_id'])) {
					$registration->responses[] = new Response([
						'question_id' => TEAM_ID_CREATED,
						'answer_text' => $preserve['team_id'],
					]);
				}
				if (!empty($preserve['franchise_id'])) {
					$registration->responses[] = new Response([
						'question_id' => FRANCHISE_ID_CREATED,
						'answer_text' => $preserve['franchise_id'],
					]);
				}
			}

			if (!$this->Registrations->save($registration, ['event' => $registration->event, 'event_obj' => $event_obj])) {
				$this->Flash->warning(__('The registration could not be saved. Please correct the errors below and try again.'));
			} else if ($this->Authentication->getIdentity()->isMe($registration)) {
				$this->Flash->success(__('Your preferences for this registration have been saved.'));
				return $this->redirect(['action' => 'checkout']);
			} else {
				$this->Flash->success(__('The registration has been saved.'));
				return $this->redirect(['controller' => 'People', 'action' => 'registrations', 'person' => $registration->person->id]);
			}
		} else {
			$this->Authorization->can(new ContextResource($registration->event, ['price' => $registration->price, 'for_edit' => $registration, 'all_rules' => true]), 'register');
		}

        $this->_reindexResponses($registration, $registration->event);

		$this->set(compact('registration'));
	}

	public function unpaid() {
		$this->Authorization->authorize($this);
		$affiliates = $this->Authentication->applicableAffiliateIDs(true);
		$registrations = $this->Registrations->find()
			->contain([
				'Events' => ['EventTypes', 'Affiliates'],
				'Prices',
				'People',
				'Payments',
			])
			->where([
				'Registrations.payment IN' => Configure::read('registration_delinquent'),
				'Events.affiliate_id IN' => $affiliates,
			])
			->order(['Events.affiliate_id', 'Registrations.payment', 'Registrations.modified']);
		if ($registrations->count() == 0) {
			$this->Flash->info(__('There are no unpaid registrations.'));
			return $this->redirect('/');
		}

		$this->set(compact('registrations', 'affiliates'));
	}

	public function credits() {
		$this->Authorization->authorize($this);
		$affiliates = $this->Authentication->applicableAffiliateIDs(true);
		$credits = $this->Registrations->People->Credits->find()
			->contain([
				'Affiliates',
				'People',
			])
			->where([
				'Credits.amount != Credits.amount_used',
				'Credits.affiliate_id IN' => $affiliates,
			])
			->order(['Credits.affiliate_id', 'Credits.created']);
		if (empty($credits)) {
			$this->Flash->info(__('There are no unused credits.'));
			return $this->redirect('/');
		}

		$this->set(compact('credits', 'affiliates'));
	}

	public function waiting() {
		$id = $this->request->getQuery('event');
		try {
			$event = $this->Registrations->Events->get($id, [
				'contain' => [
					'Registrations' => [
						'queryBuilder' => function (Query $q) {
							return $q
								->where(['Registrations.payment' => 'Waiting'])
								->order(['Registrations.created']);
						},
						'People',
						'Prices',
						'Payments',
					],
				]
			]);
		} catch (RecordNotFoundException $ex) {
			$this->Flash->info(__('Invalid event.'));
			return $this->redirect(['controller' => 'Events', 'action' => 'index']);
		} catch (InvalidPrimaryKeyException $ex) {
			$this->Flash->info(__('Invalid event.'));
			return $this->redirect(['controller' => 'Events', 'action' => 'index']);
		}

		$this->Authorization->authorize($event);
		$this->Configuration->loadAffiliate($event->affiliate_id);

		if (empty($event->registrations)) {
			$this->Flash->info(__('There is nobody on the waiting list for this event.'));
			return $this->redirect('/');
		}

		$this->set(compact('event'));
	}

	private function _reindexResponses(Registration $registration, Event $event) {
		// The entity will contain a sequentially-numbered array of responses, but we need specific numbers
		// in order for any errors that occur to be correctly reported in the form. :-(
		// TODO: Report to Cake?
		$responses = [];
		foreach ($event->questionnaire->questions as $key => $question) {
			if ($question->type == 'checkbox' && count($question->answers) > 1) {
				foreach ($question->answers as $akey => $answer) {
					$response = collection($registration->responses)->firstMatch(['question_id' => $question->id, 'answer_id' => $answer->id]);
					if ($response) {
						$responses[$key * 100 + $akey] = $response;
					}
				}
			} else {
				$response = collection($registration->responses)->firstMatch(['question_id' => $question->id]);
				if ($response) {
					$responses[$key * 100] = $response;
				}
			}
		}
		$registration->responses = $responses;
	}

}
