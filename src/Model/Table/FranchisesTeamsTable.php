<?php
namespace App\Model\Table;

/**
 * FranchisesTeams Model
 *
 * @property \Cake\ORM\Association\BelongsTo $Franchises
 * @property \Cake\ORM\Association\BelongsTo $Teams
 */
class FranchisesTeamsTable extends AppTable {

	/**
	 * Initialize method
	 *
	 * @param array $config The configuration for the Table.
	 * @return void
	 */
	public function initialize(array $config) {
		parent::initialize($config);

		$this->table('franchises_teams');
		$this->displayField('id');
		$this->primaryKey('id');

		$this->belongsTo('Franchises', [
			'foreignKey' => 'franchise_id',
			'joinType' => 'INNER',
		]);
		$this->belongsTo('Teams', [
			'foreignKey' => 'team_id',
			'joinType' => 'INNER',
		]);
	}
}
