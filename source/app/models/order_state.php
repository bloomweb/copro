<?php
class OrderState extends AppModel {
	var $name = 'OrderState';
	var $displayField = 'name';
	var $isPicture = false;
	var $sluggable = false;
	var $sortable = false;
	var $activable = false;

	var $validate = array(
		'name' => array(
			'notempty' => array(
				'rule' => array('notempty'),
				//'message' => 'Your custom message here',
				//'allowEmpty' => false,
				//'required' => false,
				//'last' => false, // Stop validation after this rule
				//'on' => 'create', // Limit validation to 'create' or 'update' operations
			),
		),
	);
	//The Associations below have been created with all possible keys, those that are not needed can be removed

	var $hasMany = array(
		'Order' => array(
			'className' => 'Order',
			'foreignKey' => 'order_state_id',
			'dependent' => false,
			'conditions' => '',
			'fields' => '',
			'order' => '',
			'limit' => '',
			'offset' => '',
			'exclusive' => '',
			'finderQuery' => '',
			'counterQuery' => ''
		)
	);

	function beforeSave(){
		return true;	
	}
}