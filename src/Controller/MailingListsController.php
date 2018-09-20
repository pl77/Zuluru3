<?php
namespace App\Controller;

use Cake\Core\Configure;
use Cake\Datasource\Exception\InvalidPrimaryKeyException;
use Cake\Datasource\Exception\RecordNotFoundException;
use Cake\ORM\Query;
use Cake\ORM\TableRegistry;
use App\Auth\HasherTrait;
use App\Exception\RuleException;

/**
 * MailingLists Controller
 *
 * @property \App\Model\Table\MailingListsTable $MailingLists
 */
class MailingListsController extends AppController {

	use HasherTrait;

	/**
	 * _publicActions method
	 *
	 * @return array of actions that can be taken even by visitors that are not logged in.

	 */
	protected function _publicActions() {
		return ['unsubscribe'];
	}

	/**
	 * isAuthorized method
	 *
	 * @return bool true if access allowed
	 */
	public function isAuthorized() {
		try {
			if ($this->UserCache->read('Person.status') == 'locked') {
				return false;
			}

			if (Configure::read('Perm.is_manager')) {
				// Managers can perform these operations
				if (in_array($this->request->params['action'], [
					'index',
					'add',
				])) {
					// If an affiliate id is specified, check if we're a manager of that affiliate
					$affiliate = $this->request->query('affiliate');
					if (!$affiliate) {
						// If there's no affiliate id, this is a top-level operation that all managers can perform
						return true;
					} else if (in_array($affiliate, $this->UserCache->read('ManagedAffiliateIDs'))) {
						return true;
					} else {
						Configure::write('Perm.is_manager', false);
					}
				}

				// Managers can perform these operations in affiliates they manage
				if (in_array($this->request->params['action'], [
					'view',
					'edit',
					'preview',
					'delete',
				])) {
					// If a list id is specified, check if we're a manager of that list's affiliate
					$list = $this->request->query('mailing_list');
					if ($list) {
						if (in_array($this->MailingLists->affiliate($list), $this->UserCache->read('ManagedAffiliateIDs'))) {
							return true;
						} else {
							Configure::write('Perm.is_manager', false);
						}
					}
				}
			}
		} catch (RecordNotFoundException $ex) {
		} catch (InvalidPrimaryKeyException $ex) {
		}

		return false;
	}

	/**
	 * Index method
	 *
	 * @return void|\Cake\Network\Response
	 */
	public function index() {
		$affiliates = $this->_applicableAffiliateIDs(true);
		$this->paginate = [
			'contain' => ['Affiliates'],
			'conditions' => [
				'MailingLists.affiliate_id IN' => $affiliates,
			],
		];

		$this->set('mailingLists', $this->paginate($this->MailingLists));
		$this->set(compact('affiliates'));
	}

	/**
	 * View method
	 *
	 * @return void|\Cake\Network\Response
	 */
	public function view() {
		$id = $this->request->query('mailing_list');
		try {
			$mailing_list = $this->MailingLists->get($id, [
				'contain' => ['Affiliates', 'Newsletters']
			]);
		} catch (RecordNotFoundException $ex) {
			$this->Flash->info(__('Invalid mailing list.'));
			return $this->redirect(['action' => 'index']);
		} catch (InvalidPrimaryKeyException $ex) {
			$this->Flash->info(__('Invalid mailing list.'));
			return $this->redirect(['action' => 'index']);
		}
		$this->Configuration->loadAffiliate($mailing_list->affiliate_id);

		$affiliates = $this->_applicableAffiliateIDs(true);
		$this->set(compact('mailing_list', 'affiliates'));
	}

	public function preview() {
		$id = $this->request->query('mailing_list');
		try {
			$mailing_list = $this->MailingLists->get($id, [
				'contain' => [
					'Affiliates',
					'Subscriptions' => [
						'queryBuilder' => function (Query $q) {
							return $q->where(['subscribed' => false]);
						}
					],
				]
			]);
		} catch (RecordNotFoundException $ex) {
			$this->Flash->info(__('Invalid mailing list.'));
			return $this->redirect(['action' => 'index']);
		} catch (InvalidPrimaryKeyException $ex) {
			$this->Flash->info(__('Invalid mailing list.'));
			return $this->redirect(['action' => 'index']);
		}
		$this->Configuration->loadAffiliate($mailing_list->affiliate_id);

		$affiliates = $this->_applicableAffiliateIDs(true);
		$this->set(compact('mailing_list', 'affiliates'));

		// Handle the rule controlling mailing list membership
		$rule_obj = $this->moduleRegistry->load('RuleEngine');
		if (!$rule_obj->init($mailing_list->rule)) {
			$this->Flash->warning(__('Failed to parse the rule: {0}', $rule_obj->parse_error));
			return $this->redirect(['action' => 'view', 'mailing_list' => $id]);
		}

		$user_model = Configure::read('Security.authModel');
		$authenticate = TableRegistry::get($user_model);
		$email_field = $authenticate->emailField;
		try {
			$people = $rule_obj->query($mailing_list->affiliate_id, [
				'OR' => [
					[
						"$user_model.$email_field !=" => '',
						'NOT' => ["$user_model.$email_field IS" => null],
					],
					[
						'People.alternate_email !=' => '',
						'NOT' => ['People.alternate_email IS' => null],
					],
					[
						"Related$user_model.$email_field !=" => '',
						'NOT' => ["Related$user_model.$email_field IS" => null],
					],
					[
						'Related.alternate_email !=' => '',
						'NOT' => ['Related.alternate_email IS' => null],
					],
				],
			]);
		} catch (RuleException $ex) {
			$this->Flash->info($ex->getMessage());
			return $this->redirect(['action' => 'view', 'mailing_list' => $id]);
		}

		if (!empty($people)) {
			$unsubscribed_ids = collection($mailing_list->subscriptions)->extract('person_id')->toArray();
			$people = array_diff($people, $unsubscribed_ids);

			$this->paginate = [
				'conditions' => [
					'People.id IN' => $people,
				],
				// TODO: Multiple default sort fields break pagination links.
				// https://github.com/cakephp/cakephp/issues/7324 has related info.
				//'order' => ['People.first_name', 'People.last_name'],
				'order' => ['People.first_name'],
				'limit' => 100,
			];
			$this->set('people', $this->paginate('People'));
		}
	}

	/**
	 * Add method
	 *
	 * @return void|\Cake\Network\Response Redirects on successful add, renders view otherwise.
	 */
	public function add() {
		$mailing_list = $this->MailingLists->newEntity();
		if ($this->request->is('post')) {
			$mailing_list = $this->MailingLists->patchEntity($mailing_list, $this->request->data);
			if ($this->MailingLists->save($mailing_list)) {
				$this->Flash->success(__('The mailing list has been saved.'));
				return $this->redirect(['action' => 'index']);
			} else {
				$this->Flash->warning(__('The mailing list could not be saved. Please correct the errors below and try again.'));
			}
		}

		$affiliates = $this->_applicableAffiliates(true);
		$this->set(compact('mailing_list', 'affiliates'));
		$this->render('edit');
	}

	/**
	 * Edit method
	 *
	 * @return void|\Cake\Network\Response Redirects on successful edit, renders view otherwise.
	 */
	public function edit() {
		$id = $this->request->query('mailing_list');
		try {
			$mailing_list = $this->MailingLists->get($id);
		} catch (RecordNotFoundException $ex) {
			$this->Flash->info(__('Invalid mailing list.'));
			return $this->redirect(['action' => 'index']);
		} catch (InvalidPrimaryKeyException $ex) {
			$this->Flash->info(__('Invalid mailing list.'));
			return $this->redirect(['action' => 'index']);
		}
		$this->Configuration->loadAffiliate($mailing_list->affiliate_id);

		if ($this->request->is(['patch', 'post', 'put'])) {
			$mailing_list = $this->MailingLists->patchEntity($mailing_list, $this->request->data);
			if ($this->MailingLists->save($mailing_list)) {
				$this->Flash->success(__('The mailing list has been saved.'));
				return $this->redirect(['action' => 'index']);
			} else {
				$this->Flash->warning(__('The mailing list could not be saved. Please correct the errors below and try again.'));
			}
		}

		$affiliates = $this->_applicableAffiliates(true);
		$this->set(compact('mailing_list', 'affiliates'));
	}

	/**
	 * Delete method
	 *
	 * @return void|\Cake\Network\Response Redirects to index.
	 */
	public function delete() {
		$this->request->allowMethod(['post', 'delete']);

		$id = $this->request->query('mailing_list');
		$dependencies = $this->MailingLists->dependencies($id);
		if ($dependencies !== false) {
			$this->Flash->warning(__('The following records reference this mailing list, so it cannot be deleted.') . '<br>' . $dependencies, ['params' => ['escape' => false]]);
			return $this->redirect(['action' => 'index']);
		}

		try {
			$mailing_list = $this->MailingLists->get($id);
		} catch (RecordNotFoundException $ex) {
			$this->Flash->info(__('Invalid mailing list.'));
			return $this->redirect(['action' => 'index']);
		} catch (InvalidPrimaryKeyException $ex) {
			$this->Flash->info(__('Invalid mailing list.'));
			return $this->redirect(['action' => 'index']);
		}

		if ($this->MailingLists->delete($mailing_list)) {
			$this->Flash->success(__('The mailing list has been deleted.'));
		} else if ($mailing_list->errors('delete')) {
			$this->Flash->warning(current($mailing_list->errors('delete')));
		} else {
			$this->Flash->warning(__('The mailing list could not be deleted. Please, try again.'));
		}

		return $this->redirect(['action' => 'index']);
	}

	public function unsubscribe() {
		$list_id = $this->request->query('list');
		if (!$list_id) {
			$this->Flash->info(__('Invalid mailing list.'));
			return $this->redirect('/');
		}
		$this->Configuration->loadAffiliate($this->MailingLists->affiliate($list_id));

		$person_id = $this->request->query('person');
		if (!$person_id) {
			$person_id = Configure::read('Perm.my_id');
			if (!$person_id) {
				$this->Flash->info(__('Invalid player.'));
				return $this->redirect('/');
			}
		}

		// We must do other permission checks here, because we allow non-logged-in users to accept
		// through email links
		$code = $this->request->query('code');
		if ($code || !Configure::read('Perm.my_id')) {
			// Authenticate the hash code
			if (!$this->_checkHash([$person_id, $list_id], $code)) {
				$this->Flash->warning(__('The authorization code is invalid.'));
				return $this->redirect('/');
			}
		}

		// Check for subscription records
		$unsubscribe = $this->MailingLists->Subscriptions->find()
			->where([
				'mailing_list_id' => $list_id,
				'person_id' => $person_id,
			])
			->first();
		if ($unsubscribe) {
			if (!$unsubscribe->subscribed) {
				$this->Flash->info(__('You are not subscribed to this mailing list.'));
				return $this->redirect('/');
			}
			$this->MailingLists->Subscriptions->patchEntity($unsubscribe, ['subscribed' => false]);
		} else {
			$unsubscribe = $this->MailingLists->Subscriptions->newEntity([
				'mailing_list_id' => $list_id,
				'person_id' => $person_id,
				'subscribed' => false,
			]);
		}
		if ($this->MailingLists->Subscriptions->save($unsubscribe)) {
			$this->Flash->success(__('You have successfully unsubscribed from this mailing list. Note that you may still be on other mailing lists for this site, and some emails (e.g. roster, attendance and score reminders) cannot be opted out of.'));
			return $this->redirect('/');
		}
		$this->Flash->warning(__('There was an error unsubscribing you from this mailing list. Please try again soon, or contact your system administrator.'));
		return $this->redirect('/');
	}

}
