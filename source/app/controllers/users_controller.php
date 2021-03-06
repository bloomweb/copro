<?php
class UsersController extends AppController {

	var $name = 'Users';

	function beforeFilter() {
		parent::beforeFilter();
		$this -> Auth -> autoRedirect = false;
		if (isset($this -> params["prefix"])) {
			$prefix = $this -> params["prefix"];
			$this -> Auth -> logoutRedirect = "/$prefix";
		} else {
			$this -> Auth -> logoutRedirect = '/';
			$this -> Auth -> loginRedirect = '/users/profile';
		}
		$this -> Auth -> allow('encrypt', 'decrypt', 'register', 'ajaxRegister', 'rememberPassword', 'validateEmail', 'sendNewsletter');
	}
	
	function beforeRender() {
		parent::beforeRender();
		$this -> set('class',$this -> action);// IMPORTANTE CLASES PARA NAVEGACION; 
	}
	
	function addUserScoreForBuying($user_id = null, $total = null) {
		if($user_id && $total) {
			$this -> User -> user_bought($user_id, $total);
		}
	}

	function getScore() {
		$this -> User -> recursive = -1;
		$user = $this -> User -> read(null, $this -> Auth -> user('id'));
		if($user) {
			return $user['User']['score'] + $user['User']['score_by_invitations'];
		} else {
			return 0;
		}
	}

	function getOwners() {
		$this -> layout = "ajax";
		$owners = array();
		$owners[''] = __('Seleccione...', true);
		$conditions = array('role_id' => 4);
		$owners_tmp = $this -> User -> find('list', array('conditions' => $conditions));
		foreach ($owners_tmp as $key => $owner) {
			$owners[$key] = $owner;
		}
		echo json_encode($owners);
		exit(0);
	}

	function register() {
		if (!empty($this -> data)) {
			$this -> data['User']['role_id'] = 3;
			$this -> data['User']['active'] = 1;
			$this -> User -> create();
			if ($this -> User -> save($this -> data)) {
				// Generar el codigo para el correo de registro
				$code = urlencode($this -> encrypt($this -> User -> id, "\xc8\xd9\xb9\x06\xd9\xe8\xc9\xd2"));
				// Enviar el correo con el codigo
				$this -> registrationEmail($this -> data['User']['email'], $code);
				$this -> Session -> setFlash(__('Registro exitoso. Por favor revisa tu correo para terminar el proceso de registro.', true));
				$this -> redirect('/');
			} else {
				$this -> Session -> setFlash(__('Ocurrió un error. Por favor, intenta de nuevo.', true));
			}
		}
		$referer_code = null;
		if (isset($this -> params['pass'][0]) && !empty($this -> params['pass'][0])) {
			$referer_code = $this -> params['pass'][0];
			$referer_code = $this -> decrypt($referer_code, "\xc8\xd9\xb9\x06\xd9\xe8\xc9\xd2");
		}
		$countries = $this -> User -> Address -> Country -> find('list', array('conditions' => array('is_present' => true)));
		//$conditions['country_id']=empty($countries) ? null : key($countries);
		//$cities =  $this -> User -> Address -> City -> find('list',array('conditions' => $conditions));
		$this -> set(compact('countries', 'cities', 'referer_code'));
	}

	function ajaxRegister() {
		if (!empty($this -> data)) {
			// Validar el nombre de usuario
			$this -> data['User']['role_id'] = 3;
			$this -> data['User']['active'] = 1;

			// Añadir campos al Address del usuario
			$this -> data['Address']['country_id'] = $this -> data['User']['country_id'];
			$this -> data['Address']['city_id'] = $this -> data['User']['city_id'];
			//echo json_encode(debug($this -> data));
			$this -> User -> create();
			if ($this -> User -> save($this -> data)) {
				// Guardar la dirección
				$this -> data['Address']['user_id'] = $this -> User -> id;
				/**
				 * Revisar si se seleccionó la opción de otro barrio
				 */
				if($this -> data['Address']['zone_id'] == 'otro') {
					$this -> User -> Address -> Zone -> create();
					$cityId = $this -> data['User']['city_id'];
					$zoneName = $this -> data['Address']['another_zone'];
					$newZone = array(
						'Zone' => array(
							'city_id' => $cityId,
							'name' => $zoneName
						)
					);
					if($this -> User -> Address -> Zone -> save($newZone)) {
						$this -> data['Address']['zone_id'] = $this -> User -> Address -> Zone -> id;
						$this -> User -> Address -> create();
						$this -> User -> Address -> save($this -> data);
					} 
				} else {
					$this -> User -> Address -> create();
					$this -> User -> Address -> save($this -> data);
				}

				// Enviar el correo con el codigo
				$code = urlencode($this -> encrypt($this -> User -> id, "\xc8\xd9\xb9\x06\xd9\xe8\xc9\xd2"));
				$this -> registrationEmail($this -> data['User']['email'], $code);
				
				// Verificar si fue referido
				if(isset($this -> data['User']['referer_code'])) {
					$referer = $this -> User -> read(null, $this -> data['User']['referer_code']);
					if($referer) {
						$this -> User -> user_invited($this -> data['User']['referer_code']);
						$this -> referalRegisteredEmail($this -> data['User']['email'], $referer['User']['email']);
					}
				}
				
				//$this -> Auth -> login($this -> data);
				$userField = $this -> User -> read(null, $this -> Auth -> user('id'));
				echo true;
			} else {
				$errors = array();
				foreach ($this->User->invalidFields() as $name => $value) {
					$errors["data[User][" . $name . "]"] = $value;
				}
				echo json_encode($errors);
			}
		}
		$this -> autoRender = false;
		Configure::write('debug', 0);
		exit(0);
	}
	
	public function sendNewsletter() {
		Configure::write('debug', 0);
		ini_set('display_errors', 0);
		$this -> loadModel('Config');
		$config = $this -> Config -> read(null, 1);
		if($config['Config']['is_newsletter_active']) {
			// Obtener las promos para enviar el correo
			$promos = $this -> getNewsletterPromos();
			// Obtener los usuarios a quienes se les enviará el correo
			$users = $this -> User -> find(
				'all',
				array(
					'conditions' => array(
						'User.active' => 1,
						'User.email_verified' => 1,
						'User.role_id' => 3
					),
					'fields' => array(
						'User.name',
						'User.last_name',
						'User.email',
						'User.score',
						'User.score_by_invitations'
					),
					'recursive' => -1
				)
			);
			foreach ($users as $key => $user) {
				$this -> newsletterEmail($user, $promos);
			}
			return 'Newsletter enviado';
		} else {
			return 'Newsletter inactivo';
		}
	}
	
	private function getNewsletterPromos() {
		// Arreglo a retornar
		$promos = array(
			'masVendida' => array(),
			'masEconomica' => array(),
			'otras' => array()
		);
		
		// Cargar modelos
		$this -> loadModel('Deal');
		$this -> loadModel('Order');
		
		// Obtener las promos vigentes
		$nowPlusFour = new DateTime('now +4 hour');
		$nowPlusFour = $nowPlusFour -> format('Y-m-d H:i:s');
		$activeDeals = $this -> Deal -> find(
			'list',
			array(
				'fields' => array('Deal.id'),
				'conditions' => array(
					//'Deal.expires >' => $nowPlusFour,
					//'Deal.amount >' => 0
				)
			)
		);
		
		// Formatear id's para la consulta SQL
		$tmpActiveDeals = '';
		foreach ($activeDeals as $key => $id) {
			$tmpActiveDeals .= $id . ',';
		}
		$tmpActiveDeals = '(' . substr($tmpActiveDeals, 0, strlen($tmpActiveDeals) - 1) . ')';
		$SQLactiveDeals = $tmpActiveDeals;
		
		// Obtener la promo más vendida
		// OJO: Realizar la busqueda sólo si hay id's
		if(strlen($SQLactiveDeals) > 2) {
			// Obtener la más comprada
			$masVendida = $this -> Order -> query(
				"SELECT `deal_id`, count(deal_id) AS `VecesComprado`
				FROM `orders` AS `Order`
				WHERE `deal_id` IN $SQLactiveDeals
				GROUP BY `deal_id`
				ORDER BY VecesComprado DESC;"
			);
			$promos['masVendida'] = $this -> Deal -> find(
				'first',
				array(
					'conditions' => array(
						'Deal.id' => $masVendida[0]['Order']['deal_id']
					),
					'recursive' => 0
				)
			);
		}
		
		// Obtener la promo más económica
		$promos['masEconomica'] = $this -> Deal -> find(
			'first',
			array(
				'conditions' => array(
					'Deal.id' => $activeDeals
				),
				'order' => array(
					'Deal.price' => 'ASC'
				),
				'recursive' => 0
			)
		);
		
		// Obtener otras
		// Quitar del listado los deals ya seleccionados
		if(!empty($promos['masVendida'])) {
			unset($activeDeals[$promos['masVendida']['Deal']['id']]);
		}
		if(!empty($promos['masEconomica'])) {
			unset($activeDeals[$promos['masEconomica']['Deal']['id']]);
		}
		
		// Buscar otras sólo si hay contenido para buscar
		if(count($activeDeals) >= 1) {
			// Generar id's aleatorias
			$randomDeals = array();
			for ($total = 0; $total < 5  ; $total += 1) {
				$id = 0;
				$seguirGenerando = true;
				do {
					$seguirGenerando = true;
					if(count($activeDeals) >= 1) {
						$id = rand(min($activeDeals), max($activeDeals));
						if(in_array($id, $activeDeals) && !in_array($id, $randomDeals)) {
							$randomDeals[$id] = $id;
							unset($activeDeals[$id]);
							$seguirGenerando = false;
						}
					} else {
						$seguirGenerando = false;
					}
				} while($seguirGenerando);
			}		
			$promos['otras'] = $this -> Deal -> find(
				'all',
				array(
					'conditions' => array(
						'Deal.id' => $randomDeals
					),
					//'limit' => 5,
					'recursive' => 0
				)
			);
		}
		
		return $promos;
	}
	
	private function newsletterEmail($user = null, $promos = null) {
		/**
		 * Asignar las variables del componente Email
		 */
		if ($user['User']['email'] && $promos) {
			// Address the message is going to (string). Separate the addresses with a comma if you want to send the email to more than one recipient.
			$this -> Email -> to = $user['User']['email'];
			// array of addresses to cc the message to
			$this -> Email -> cc = '';
			// array of addresses to bcc (blind carbon copy) the message to
			$this -> Email -> bcc = '';
			// reply to address (string)
			$this -> Email -> replyTo = Configure::read('reply_info_mail');
			// Return mail address that will be used in case of any errors(string) (for mail-daemon/errors)
			$this -> Email -> return = Configure::read('reply_info_mail');
			// from address (string)
			$this -> Email -> from = Configure::read('info_mail');
			// subject for the message (string)
			$this -> Email -> subject = Configure::read('site_name') . ' :: ' .  __('Promociones Diarias', true);
			// The email element to use for the message (located in app/views/elements/email/html/ and app/views/elements/email/text/)
			$this -> Email -> template = 'newsletter_email';
			// The layout used for the email (located in app/views/layouts/email/html/ and app/views/layouts/email/text/)
			//$this -> Email -> layout = '';
			// Length at which lines should be wrapped. Defaults to 70. (integer)
			//$this -> Email -> lineLength = '';
			// how do you want message sent string values of text, html or both
			$this -> Email -> sendAs = 'html';
			// array of files to send (absolute and relative paths)
			//$this -> Email -> attachments = '';
			// how to send the message (mail, smtp [would require smtpOptions set below] and debug)
			$this -> Email -> delivery = 'smtp';
			// associative array of options for smtp mailer (port, host, timeout, username, password, client)
			$this -> Email -> smtpOptions = array('port' => '465', 'timeout' => '30', 'host' => 'ssl://smtp.gmail.com', 'username' => Configure::read('register_mail'), 'password' => Configure::read('password_register_mail'), 'client' => 'smtp_helo_comopromos.com');

			/**
			 * Asignar cosas al template
			 */
			$this -> set('user', $user);
			$this -> set('masVendida', $promos['masVendida']);
			$this -> set('masEconomica', $promos['masEconomica']);
			$this -> set('otras', $promos['otras']);

			/**
			 * Enviar el correo
			 */
			Configure::write('debug', 0);
			$this -> Email -> send();
			$this -> set('smtp_errors', $this -> Email -> smtpError);
			$this -> Email -> reset();
		}

	}

	public function refer() {
		if($this -> Auth -> user('id')) {
			if (!empty($this -> data)) {
				$referals_made = false;
				if (isset($this -> data['User']['correo_recomendado_1']) && !empty($this -> data['User']['correo_recomendado_1'])) {
					if (!$this -> User -> findByEmail($this -> data['User']['correo_recomendado_1'])) {
						$this -> referalEmail($this -> data['User']['correo_recomendado_1'], urlencode($this -> encrypt($this -> Auth -> user('id'), "\xc8\xd9\xb9\x06\xd9\xe8\xc9\xd2")));
						$referals_made = true;
					}
				}
				if (isset($this -> data['User']['correo_recomendado_2']) && !empty($this -> data['User']['correo_recomendado_2'])) {
					if (!$this -> User -> findByEmail($this -> data['User']['correo_recomendado_2'])) {
						$this -> referalEmail($this -> data['User']['correo_recomendado_2'], urlencode($this -> encrypt($this -> Auth -> user('id'), "\xc8\xd9\xb9\x06\xd9\xe8\xc9\xd2")));
						$referals_made = true;
					}
				}
				if (isset($this -> data['User']['correo_recomendado_3']) && !empty($this -> data['User']['correo_recomendado_3'])) {
					if (!$this -> User -> findByEmail($this -> data['User']['correo_recomendado_3'])) {
						$this -> referalEmail($this -> data['User']['correo_recomendado_3'], urlencode($this -> encrypt($this -> Auth -> user('id'), "\xc8\xd9\xb9\x06\xd9\xe8\xc9\xd2")));
						$referals_made = true;
					}
				}
				if (isset($this -> data['User']['correo_recomendado_4']) && !empty($this -> data['User']['correo_recomendado_4'])) {
					if (!$this -> User -> findByEmail($this -> data['User']['correo_recomendado_4'])) {
						$this -> referalEmail($this -> data['User']['correo_recomendado_4'], urlencode($this -> encrypt($this -> Auth -> user('id'), "\xc8\xd9\xb9\x06\xd9\xe8\xc9\xd2")));
						$referals_made = true;
					}
				}
				if (isset($this -> data['User']['correo_recomendado_5']) && !empty($this -> data['User']['correo_recomendado_5'])) {
					if (!$this -> User -> findByEmail($this -> data['User']['correo_recomendado_5'])) {
						$this -> referalEmail($this -> data['User']['correo_recomendado_5'], urlencode($this -> encrypt($this -> Auth -> user('id'), "\xc8\xd9\xb9\x06\xd9\xe8\xc9\xd2")));
						$referals_made = true;
					}
				}
				if($referals_made) {
					// $this -> Session -> setFlash(__('Thank you for inviting your friends over. If they register you will receive points for each succesfull registration!', true));
					$this -> Session -> setFlash(__('Gracias por invitar a tus amigos. ¡Cada registro exitoso te dará puntos!', true));
				} else {
					$this -> Session -> setFlash(__('Esperamos invites más personas pronto.', true));
				}
				$this -> redirect(array('action' => 'profile'));
			}
		} else {
			$this -> Session -> setFlash(__('Debes de haber iniciado sesión para referir a tus amigos', true));
		}
		$this -> layout = "profile";
		$this->set('code',urlencode($this -> encrypt($this -> Auth -> user('id'), "\xc8\xd9\xb9\x06\xd9\xe8\xc9\xd2")));
	}

	public function registrationEmail($email = null, $code = null) {
		/**
		 * Asignar las variables del componente Email
		 */
		if ($email && $code) {
			// Address the message is going to (string). Separate the addresses with a comma if you want to send the email to more than one recipient.
			$this -> Email -> to = $email;
			// array of addresses to cc the message to
			$this -> Email -> cc = '';
			// array of addresses to bcc (blind carbon copy) the message to
			$this -> Email -> bcc = '';
			// reply to address (string)
			$this -> Email -> replyTo = Configure::read('reply_register_mail');
			// Return mail address that will be used in case of any errors(string) (for mail-daemon/errors)
			$this -> Email -> return = Configure::read('reply_register_mail');
			// from address (string)
			$this -> Email -> from = Configure::read('register_mail');
			// subject for the message (string)
			$this -> Email -> subject = __('Registro al sitio: ', true) . Configure::read('site_name');
			// The email element to use for the message (located in app/views/elements/email/html/ and app/views/elements/email/text/)
			$this -> Email -> template = 'registration_email';
			// The layout used for the email (located in app/views/layouts/email/html/ and app/views/layouts/email/text/)
			//$this -> Email -> layout = '';
			// Length at which lines should be wrapped. Defaults to 70. (integer)
			//$this -> Email -> lineLength = '';
			// how do you want message sent string values of text, html or both
			$this -> Email -> sendAs = 'html';
			// array of files to send (absolute and relative paths)
			//$this -> Email -> attachments = '';
			// how to send the message (mail, smtp [would require smtpOptions set below] and debug)
			$this -> Email -> delivery = 'smtp';
			// associative array of options for smtp mailer (port, host, timeout, username, password, client)
			$this -> Email -> smtpOptions = array('port' => '465', 'timeout' => '30', 'host' => 'ssl://smtp.gmail.com', 'username' => Configure::read('register_mail'), 'password' => Configure::read('password_register_mail'), 'client' => 'smtp_helo_comopromos.com');

			/**
			 * Asignar cosas al template
			 */
			$this -> set('code', $code);

			/**
			 * Enviar el correo
			 */
			Configure::write('debug', 0);
			$this -> Email -> send();
			$this -> set('smtp_errors', $this -> Email -> smtpError);
			$this -> Email -> reset();
		}

	}
	
	public function referalRegisteredEmail($email = null, $referer_email = null) {
		/**
		 * Asignar las variables del componente Email
		 */
		if ($email && $referer_email) {
			// Address the message is going to (string). Separate the addresses with a comma if you want to send the email to more than one recipient.
			$this -> Email -> to = $referer_email;
			// array of addresses to cc the message to
			$this -> Email -> cc = '';
			// array of addresses to bcc (blind carbon copy) the message to
			$this -> Email -> bcc = '';
			// reply to address (string)
			$this -> Email -> replyTo = Configure::read('info_mail');
			// Return mail address that will be used in case of any errors(string) (for mail-daemon/errors)
			$this -> Email -> return = Configure::read('reply_info_mail');
			// from address (string)
			$this -> Email -> from = Configure::read('info_mail');
			// subject for the message (string)
			$this -> Email -> subject = __('Se ha registrado un amigo: ', true) . Configure::read('site_name');
			// The email element to use for the message (located in app/views/elements/email/html/ and app/views/elements/email/text/)
			$this -> Email -> template = 'referal_registered_email';
			// The layout used for the email (located in app/views/layouts/email/html/ and app/views/layouts/email/text/)
			//$this -> Email -> layout = '';
			// Length at which lines should be wrapped. Defaults to 70. (integer)
			//$this -> Email -> lineLength = '';
			// how do you want message sent string values of text, html or both
			$this -> Email -> sendAs = 'html';
			// array of files to send (absolute and relative paths)
			//$this -> Email -> attachments = '';
			// how to send the message (mail, smtp [would require smtpOptions set below] and debug)
			$this -> Email -> delivery = 'smtp';
			// associative array of options for smtp mailer (port, host, timeout, username, password, client)
			$this -> Email -> smtpOptions = array('port' => '465', 'timeout' => '30', 'host' => 'ssl://smtp.gmail.com', 'username' => Configure::read('info_mail'), 'password' => Configure::read('password_info_mail'), 'client' => 'smtp_helo_comopromos.com');

			/**
			 * Asignar cosas al template
			 */
			$ths -> loadModel('Config');
			$config = $this -> Config -> read(null, 1);
			$this -> set('referer_email', $referer_email);
			$this -> set('email', $email);
			$this -> set('points', $config['Config']['score_by_invitations']);

			/**
			 * Enviar el correo
			 */
			Configure::write('debug', 0);
			$this -> Email -> send();
			$this -> set('smtp_errors', $this -> Email -> smtpError);
			$this -> Email -> reset();
		}

	}

	public function referalEmail($email = null, $code = null) {
		/**
		 * Asignar las variables del componente Email
		 */
		if ($email && $code) {
			// Address the message is going to (string). Separate the addresses with a comma if you want to send the email to more than one recipient.
			$this -> Email -> to = $email;
			// array of addresses to cc the message to
			$this -> Email -> cc = '';
			// array of addresses to bcc (blind carbon copy) the message to
			$this -> Email -> bcc = '';
			// reply to address (string)
			$this -> Email -> replyTo = Configure::read('info_mail');
			// Return mail address that will be used in case of any errors(string) (for mail-daemon/errors)
			$this -> Email -> return = Configure::read('reply_info_mail');
			// from address (string)
			$this -> Email -> from = Configure::read('info_mail');
			// subject for the message (string)
			$this -> Email -> subject = __('Recomendación del sitio: ', true) . Configure::read('site_name');
			// The email element to use for the message (located in app/views/elements/email/html/ and app/views/elements/email/text/)
			$this -> Email -> template = 'referal_email';
			// The layout used for the email (located in app/views/layouts/email/html/ and app/views/layouts/email/text/)
			//$this -> Email -> layout = '';
			// Length at which lines should be wrapped. Defaults to 70. (integer)
			//$this -> Email -> lineLength = '';
			// how do you want message sent string values of text, html or both
			$this -> Email -> sendAs = 'html';
			// array of files to send (absolute and relative paths)
			//$this -> Email -> attachments = '';
			// how to send the message (mail, smtp [would require smtpOptions set below] and debug)
			$this -> Email -> delivery = 'smtp';
			// associative array of options for smtp mailer (port, host, timeout, username, password, client)
			$this -> Email -> smtpOptions = array('port' => '465', 'timeout' => '30', 'host' => 'ssl://smtp.gmail.com', 'username' => Configure::read('info_mail'), 'password' => Configure::read('password_info_mail'), 'client' => 'smtp_helo_comopromos.com');

			/**
			 * Asignar cosas al template
			 */
			$this -> set('code', $code);

			/**
			 * Enviar el correo
			 */
			Configure::write('debug', 0);
			$this -> Email -> send();
			$this -> set('smtp_errors', $this -> Email -> smtpError);
			$this -> Email -> reset();
		}

	}

	function validateEmail($code = null) {
		if (!$code && !empty($this -> data)) {
			if (isset($this -> data['User']['validation_code']) && !empty($this -> data['User']['validation_code'])) {
				$code = $this -> data['User']['validation_code'];
			}
		}
		if ($code) {
			$max_id = $this -> User -> find('first', array('fields' => array('MAX(User.id) as max_id'), 'recursive' => -1));
			$max_id = $max_id[0]['max_id'];
			$user = null;
			$code = urldecode($code);
			for ($id_tested = $max_id; $id_tested > 0; $id_tested -= 1) {
				if ($code == $this -> encrypt($id_tested, "\xc8\xd9\xb9\x06\xd9\xe8\xc9\xd2")) {
					$user = $this -> User -> read(null, $id_tested);
					break;
				}
			}
			if ($user) {
				$user['User']['email_verified'] = true;
				if ($this -> User -> save($user)) {
					// Bonificación por registro por registro
					$this -> User -> user_registered($user['User']['id']);

					$this -> Session -> setFlash(__('Gracias por validar el correo', true));
					if ($this -> Auth -> login($user)) {
						$this -> redirect(array('controller' => 'users', 'action' => 'refer'));
					} else {
						$this -> redirect(array('controller' => 'users', 'action' => 'login'));
					}
				} else {
					$this -> Session -> setFlash(__('Ocurrió un error con la activación de tu usuario.', true));
					$this -> redirect('/');
				}
			} else {
				$this -> Session -> setFlash(__('Ocurrió un error con la activación de tu usuario.', true));
				$this -> redirect('/');
			}
		} else {
			$this -> Session -> setFlash(__('Ocurrió un error con la activación de tu usuario.', true));
			$this -> redirect('/');
		}
	}

	function profile() {
		$this -> layout = "profile";
		/*$orders = $this -> User -> Order -> find(
			'all',
			array(
				'conditions' => array(
					'Order.user_id' => $this -> Auth -> user('id')
				)
			)
		);
		$this -> set('orders', $orders);*/
		$this -> paginate = array(
			'Order' => array(
				'conditions' => array(
					'Order.user_id' => $this -> Auth -> user('id')
				),
				'contain'=>array(
					'Address','Deal','OrderState','Deal.Restaurant'
				)
			)
		);
		$this -> set('orders', $this -> paginate('Order'));
	}

	function edit() {
		$id = $this -> Auth -> user('id');
		if($id) {
			$this -> layout = "profile";
			if (!$id && empty($this -> data)) {
				$this -> Session -> setFlash(__('Usuario no válido', true));
				$this -> redirect(array('action' => 'index'));
			}
	
			if (!empty($this -> data)) {
				if (!empty($this -> data['User']['pass']))
					$this -> data['User']['password'] = $this -> Auth -> password($this -> data['User']['pass']);
				if ($this -> User -> saveAll($this -> data)) {
					$this -> Session -> setFlash(__('Se actualizó la información.', true));
					//$this -> redirect(array('action' => 'profile'));
				} else {
					$this -> Session -> setFlash(__('No se pudo actualizar la información', true));
				}
			}
			if (empty($this -> data)) {
				$this -> data = $this -> User -> read(null, $id);
			}
			$roles = $this -> User -> Role -> find('list');
			$countries = $this -> User -> Address -> Country -> find('list');
			//$conditions['country_id']=empty($countries) ? null : key($countries);
			$cities = $this -> User -> Address -> City -> find('list');
			$this -> set('userId', $id);
			$this -> set(compact('countries', 'cities'));
			$this -> set(compact('roles'));
		} else {
			$this -> redirect('/');
		}
	}

	function updateAddresses() {
		$id = $this -> Auth -> user('id');
		if($id) {
			$this -> layout = "profile";
			if (!$id && empty($this -> data)) {
				$this -> Session -> setFlash(__('Usuario no valido', true));
				$this -> redirect(array('action' => 'index'));
			}
	
			if (!empty($this -> data)) {
				if ($this -> User -> Address -> save($this -> data)) {
					$this -> Session -> setFlash(__('Se registró la dirección', true));
					//	$this -> redirect(array('action' => 'profile'));
				} else {
					$this -> Session -> setFlash(__('La dirección no se pudo registrar. Por favor, intente de nuevo.', true));
				}
			}
			$this -> User -> Address -> recursive = -1;
			$addresses = $this -> User -> Address -> find('all', array('conditions' => array('user_id' => $id)));
			$roles = $this -> User -> Role -> find('list');
			$countries = $this -> User -> Address -> Country -> find('list');
			//$conditions['country_id']=empty($countries) ? null : key($countries);
			//$cities = $this -> User -> Address -> City -> find('list');
			//$zones = $this -> User -> Address -> Zone -> find('list');
			
			$cities = $this -> User -> Address -> City -> find('all', array('recursive' => -1));
			$cities_list = array();
			foreach ($cities as $key => $city) {
				$cities_list[$city['City']['id']] = $city['City']['name'];
			}
			$cities = $cities_list;
			$zones = $this -> User -> Address -> Zone -> find('all', array('recursive' => -1));
			$zones_list = array();
			foreach ($zones as $key => $zone) {
				$zones_list[$zone['Zone']['id']] = $zone['Zone']['name'];
			}
			$zones = $zones_list;
			
			$this -> set('userId', $id);
			$this -> set(compact('countries', 'cities', 'addresses', 'zones'));
			$this -> set(compact('roles'));
		} else {
			$this -> redirect('/');
		}
	}

	function changePassword() {
		$id = $this -> Auth -> user('id');
		if($id) {
			$this -> layout = "profile";
			if (!$id && empty($this -> data)) {
				$this -> Session -> setFlash(__('Usuario no válido', true));
				$this -> redirect(array('action' => 'profile'));
			}
			if (!empty($this -> data)) {
				$user = $this -> User -> findById($this -> data['User']['id']);
				if ($user['User']['password'] == $this -> Auth -> password($this -> data['User']['old_password'])) {
					$user['User']['password'] = $this -> Auth -> password($this -> data['User']['new_password']);
					if ($this -> data['User']['new_password'] == $this -> data['User']['confirm_password'] && $this -> User -> save($user)) {
						$this -> Session -> setFlash(__('Se ha actualizado tu contraseña', true));
						$this -> redirect($this -> referer());
					} else {
						$this -> Session -> setFlash(__('No coincide la confirmación de la contraseña', true));
						$this -> redirect($this -> referer());
					}
	
				} else {
					$this -> Session -> setFlash(__('Su contraseña anterior no es válida', true));
					$this -> redirect($this -> referer());
				}
			}
			$this -> set('userId', $id);
		} else {
			$this -> redirect('/');
		}
	}

	function resetPassword($data = null) {
		//$this -> autoRender = false;
		if ($data) {
			$this -> User -> recursive = 0;
			$user = $this -> User -> findByEmail(trim($data));
			if (!empty($user)) {
				$email = $user['User']['email'];
				$password = $this -> createPassword();
				$user['User']['password'] = $this -> Auth -> password($password);
				if ($this -> User -> save($user)) {
					$this -> passwordEmail($email, $email, $password);
					//echo json_encode(array('success'=>true, 'message'=>__("An email has been sent to $email with the new password.", true)));
					$this -> Session -> setFlash(__('Se ha enviado un correo a ', true) . $email . __(' con la nueva contraseña.', true));
				} else {
					//echo json_encode(array('success'=>false, 'message'=>__('An error occurred in the process. Please try again.', true)));
					$this -> Session -> setFlash(__('Ocurrio un error en el proceso. Por favor, intente de nuevo', true));
				}
			} else {
				//echo json_encode(array('success'=>false, 'message'=>__('No user with that email registered', true)));
				$this -> Session -> setFlash(__('No existe un usuario con ese correo registrado.', true));
			}
		}
		$this -> redirect('/');
		//exit(0);
	}

	private function createPassword() {
		$str = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz1234567890";
		$cad = "";
		for ($i = 0; $i < 8; $i++) {
			$cad .= substr($str, rand(0, 62), 1);
		}
		return $cad;
	}

	private function passwordEmail($email = null, $username = null, $password = null) {
		/**
		 * Asignar las variables del componente Email
		 */
		if ($email && $username && $password) {
			// Address the message is going to (string). Separate the addresses with a comma if you want to send the email to more than one recipient.
			$this -> Email -> to = $email;
			// array of addresses to cc the message to
			$this -> Email -> cc = '';
			// array of addresses to bcc (blind carbon copy) the message to
			$this -> Email -> bcc = '';
			// reply to address (string)
			$this -> Email -> replyTo = Configure::read('reply_password_mail');
			// Return mail address that will be used in case of any errors(string) (for mail-daemon/errors)
			$this -> Email -> return = Configure::read('reply_password_mail');
			// from address (string)
			$this -> Email -> from = Configure::read('password_mail');
			// subject for the message (string)
			$this -> Email -> subject = __('Petición de cambio de contaseña ', true) . Configure::read('site_name');
			// The email element to use for the message (located in app/views/elements/email/html/ and app/views/elements/email/text/)
			$this -> Email -> template = 'password_email';
			// The layout used for the email (located in app/views/layouts/email/html/ and app/views/layouts/email/text/)
			//$this -> Email -> layout = '';
			// Length at which lines should be wrapped. Defaults to 70. (integer)
			//$this -> Email -> lineLength = '';
			// how do you want message sent string values of text, html or both
			$this -> Email -> sendAs = 'html';
			// array of files to send (absolute and relative paths)
			//$this -> Email -> attachments = '';
			// how to send the message (mail, smtp [would require smtpOptions set below] and debug)
			$this -> Email -> delivery = 'smtp';
			// associative array of options for smtp mailer (port, host, timeout, username, password, client)
			$this -> Email -> smtpOptions = array('port' => '465', 'timeout' => '30', 'host' => 'ssl://smtp.gmail.com', 'username' => Configure::read('password_mail'), 'password' => Configure::read('password_password_mail'), 'client' => 'smtp_helo_comopromos.com');

			/**
			 * Asignar cosas al template
			 */
			$this -> set('username', $username);
			$this -> set('password', $password);

			/**
			 * Enviar el correo
			 */
			Configure::write('debug', 0);
			$this -> Email -> send();
			$this -> set('smtp_errors', $this -> Email -> smtpError);
			$this -> Email -> reset();
		}

	}
	
	function admin_modifyPassword() {
		
		$this -> User -> recursive = -1;
		$id = $this -> Auth -> user('id');
		
		if (!$id && empty($this -> data)) {
			$this -> redirect('/');
		}

		if (!empty($this -> data)) {
			if($this -> data['User']['id'] == $id) {
				$user = $this -> User -> read(null, $id);
				$this -> data['User']['role_id'] = $user['User']['role_id'];
				if($this -> Auth -> password($this -> data['User']['old_pass']) == $user['User']['password']) {
					if($this -> data['User']['new_pass'] == $this -> data['User']['verified_pass']) {
						if($this -> data['User']['new_pass'] == $this -> data['User']['old_pass']) {
							$this -> Session -> setFlash(__('La contraseña nueva coincide con la antigua. Rectifique e intente de nuevo.', true));
						} else {
							$this -> data['User']['password'] = $this -> Auth -> password($this -> data['User']['new_pass']);
							$this -> data['User']['email_verified'] = $user['User']['email_verified'];
							$this -> User -> create();
							if ($this -> User -> save($this -> data)) {
								$this -> Session -> setFlash(__('Se modificó la contraseña', true));
							} else {
								$this -> Session -> setFlash(__('No se pudo modificar la contraseña. Por favor, intente de nuevo.', true));
							}
						}
					} else {
						$this -> Session -> setFlash(__('La nueva contraseña no coincide con la verificada. Revice e intente de nuevo.', true));
					}
				} else {
					$this -> Session -> setFlash(__('La contraseña anterior no coincide. Verifique e intente de nuevo.', true));
				}
			} else {
				$this -> redirect('/');
			}
		}

		if (empty($this -> data)) {
			$this -> data = $this -> User -> read(null, $id);
		}
		
	}
	
	function manager_modifyPassword() {
		
		$this -> User -> recursive = -1;
		$id = $this -> Auth -> user('id');
		
		if (!$id && empty($this -> data)) {
			$this -> redirect('/');
		}

		if (!empty($this -> data)) {
			if($this -> data['User']['id'] == $id) {
				$user = $this -> User -> read(null, $id);
				$this -> data['User']['role_id'] = $user['User']['role_id'];
				if($this -> Auth -> password($this -> data['User']['old_pass']) == $user['User']['password']) {
					if($this -> data['User']['new_pass'] == $this -> data['User']['verified_pass']) {
						if($this -> data['User']['new_pass'] == $this -> data['User']['old_pass']) {
							$this -> Session -> setFlash(__('La contraseña nueva coincide con la antigua. Rectifique e intente de nuevo.', true));
						} else {
							$this -> data['User']['password'] = $this -> Auth -> password($this -> data['User']['new_pass']);
							$this -> data['User']['email_verified'] = $user['User']['email_verified'];
							$this -> User -> create();
							if ($this -> User -> save($this -> data)) {
								$this -> Session -> setFlash(__('Se modificó la contraseña', true));
							} else {
								$this -> Session -> setFlash(__('No se pudo modificar la contraseña. Por favor, intente de nuevo.', true));
							}
						}
					} else {
						$this -> Session -> setFlash(__('La nueva contraseña no coincide con la verificada. Revice e intente de nuevo.', true));
					}
				} else {
					$this -> Session -> setFlash(__('La contraseña anterior no coincide. Verifique e intente de nuevo.', true));
				}
			} else {
				$this -> redirect('/');
			}
		}

		if (empty($this -> data)) {
			$this -> data = $this -> User -> read(null, $id);
		}
		
	}
	
	function owner_modifyPassword() {
		
		$this -> User -> recursive = -1;
		$id = $this -> Auth -> user('id');
		
		if (!$id && empty($this -> data)) {
			$this -> redirect('/');
		}

		if (!empty($this -> data)) {
			if($this -> data['User']['id'] == $id) {
				$user = $this -> User -> read(null, $id);
				$this -> data['User']['role_id'] = $user['User']['role_id'];
				if($this -> Auth -> password($this -> data['User']['old_pass']) == $user['User']['password']) {
					if($this -> data['User']['new_pass'] == $this -> data['User']['verified_pass']) {
						if($this -> data['User']['new_pass'] == $this -> data['User']['old_pass']) {
							$this -> Session -> setFlash(__('La contraseña nueva coincide con la antigua. Rectifique e intente de nuevo.', true));
						} else {
							$this -> data['User']['password'] = $this -> Auth -> password($this -> data['User']['new_pass']);
							$this -> data['User']['email_verified'] = $user['User']['email_verified'];
							$this -> User -> create();
							if ($this -> User -> save($this -> data)) {
								$this -> Session -> setFlash(__('Se modificó la contraseña', true));
							} else {
								$this -> Session -> setFlash(__('No se pudo modificar la contraseña. Por favor, intente de nuevo.', true));
							}
						}
					} else {
						$this -> Session -> setFlash(__('La nueva contraseña no coincide con la verificada. Revice e intente de nuevo.', true));
					}
				} else {
					$this -> Session -> setFlash(__('La contraseña anterior no coincide. Verifique e intente de nuevo.', true));
				}
			} else {
				$this -> redirect('/');
			}
		}

		if (empty($this -> data)) {
			$this -> data = $this -> User -> read(null, $id);
		}
		
	}

	function admin_index() {
		$this -> User -> recursive = 0;
		if(!empty($this -> data)) {
			// Armar condiciones de paginado
			$conditions = array();
			if(isset($this -> data['Filtros']['email']) && !empty($this -> data['Filtros']['email'])) {
				$conditions['User.email LIKE'] = '%' . $this -> data['Filtros']['email'] . '%';
			}
			if(isset($this -> data['Filtros']['nombres']) && !empty($this -> data['Filtros']['nombres'])) {
				$conditions['User.name LIKE'] = '%' . $this -> data['Filtros']['nombres'] . '%';
			}
			if(isset($this -> data['Filtros']['apellidos']) && !empty($this -> data['Filtros']['apellidos'])) {
				$conditions['User.last_name LIKE'] = '%' . $this -> data['Filtros']['apellidos'] . '%';
			}
			if(isset($this -> data['Filtros']['rol']) && !empty($this -> data['Filtros']['rol'])) {
				$conditions['User.role_id'] = $this -> data['Filtros']['rol'];
			}
			if(isset($this -> data['Filtros']['ciudad']) && !empty($this -> data['Filtros']['ciudad'])) {
				$conditions['User.city_id'] = $this -> data['Filtros']['ciudad'];
			}
			// Asignar condiciones de paginado
			$this -> paginate = array(
				'order' => array('User.id' => 'DESC'),
				'conditions' => $conditions
			);
		}
		$this -> set('users', $this -> paginate());
		$this -> set('roles', $this -> User -> Role -> find('list'));
		$this -> set('cities', $this -> User -> City -> find('list'));
	}

	function admin_view($id = null) {
		if (!$id) {
			$this -> Session -> setFlash(__('Usuario no válido', true));
			$this -> redirect(array('action' => 'index'));
		}
		$this -> User -> recursive = 2;
		$this -> set('user', $this -> User -> read(null, $id));
	}

	function admin_add() {
		if (!empty($this -> data)) {
			$this -> data['User']['password'] = $this -> Auth -> password($this -> data['User']['pass']);
			$this -> data['User']['email_verified'] = 1;
			$this -> User -> create();
			if ($this -> User -> save($this -> data)) {
				$this -> Session -> setFlash(__('Se registró el usuario', true));
				$this -> redirect(array('action' => 'index'));
			} else {
				$this -> Session -> setFlash(__('No se pudo registrar el usuario. Si el usuario no es un admin verifica que hayas seleccionado una ciudad e intenta de nuevo.', true));
			}
		}
		$roles = $this -> User -> Role -> find('list');
		$cities = $this -> User -> City -> find('list');
		$this -> set(compact('roles', 'cities'));
	}

	function admin_edit($id = null) {
		if (!$id && empty($this -> data)) {
			$this -> Session -> setFlash(__('Usuario no válido', true));
			$this -> redirect(array('action' => 'index'));
		}

		if (!empty($this -> data)) {
			if (!empty($this -> data['User']['new_pass'])) {
				$this -> data['User']['password'] = $this -> Auth -> password($this -> data['User']['new_pass']);
			}
			$this -> User -> create();
			if ($this -> User -> save($this -> data)) {
				$this -> Session -> setFlash(__('Se registró el usuario', true));
				$this -> redirect(array('action' => 'index'));
			} else {
				$this -> Session -> setFlash(__('No se pudo registrar el usuario. Si el usuario no es un admin verifica que hayas seleccionado una ciudad e intenta de nuevo.', true));
			}
		}

		if (empty($this -> data)) {
			$this -> data = $this -> User -> read(null, $id);
		}
		$roles = $this -> User -> Role -> find('list');
		$cities = $this -> User -> City -> find('list');
		$this -> set(compact('roles', 'cities'));
	}

	function admin_delete($id = null) {
		if (!$id) {
			$this -> Session -> setFlash(__('ID de usuario no válida', true));
			$this -> redirect(array('action' => 'index'));
		}
		if ($this -> User -> delete($id)) {
			$this -> Session -> setFlash(__('Usuario eliminado', true));
			$this -> redirect(array('action' => 'index'));
		}
		$this -> Session -> setFlash(__('El usuario no fue eliminado', true));
		$this -> redirect(array('action' => 'index'));
	}

	function admin_setInactive($id = null) {
		if (!$id) {
			$this -> Session -> setFlash(__('ID de usuario no válida', true));
			$this -> redirect(array('action' => 'index'));
		}
		$oldData = $this -> User -> read(null, $id);
		$oldData["User"]["active"] = false;
		if ($this -> User -> save($oldData)) {
			$this -> Session -> setFlash(__('Usuario activado', true));
			$this -> redirect(array('action' => 'index'));
		}
		$this -> Session -> setFlash(__('El usuario no fue activado', true));
		$this -> redirect(array('action' => 'index'));
	}

	function admin_setActive($id = null) {
		if (!$id) {
			$this -> Session -> setFlash(__('ID de usuario no válida', true));
			$this -> redirect(array('action' => 'index'));
		}
		$oldData = $this -> User -> read(null, $id);
		$oldData["User"]["active"] = true;
		if ($this -> User -> save($oldData)) {
			$this -> Session -> setFlash(__('Se activo el usuario', true));
			$this -> redirect(array('action' => 'index'));
		}
		$this -> Session -> setFlash(__('No se activo el usuario', true));
		$this -> redirect(array('action' => 'index'));
	}

	function login() {
		if (isset($this -> data['User']['email']) && !empty($this -> data['User']['email']) && isset($this -> data['User']['password']) && !empty($this -> data['User']['password'])) {
			$this -> User -> recursive = -1;
			$user = $this -> User -> findByEmail($this -> data['User']['email']);
			if (!empty($user)) {
				if ($user['User']['email_verified']) {
					$this -> Auth -> login($user);
					$this -> redirect($this -> Auth -> redirect());
				} else {
					$this -> Auth -> logout($user);
					$this -> redirect(array('controller' => 'users', 'action' => 'validateEmail'));
				}
			} else {
				$this -> Session -> setFlash($this -> Auth -> loginError, $this -> Auth -> flashElement, array(), 'auth');
			}
		}
	}

	function owner_login() {
		$this -> layout = "ez/login";
		if (isset($this -> data['User']['email']) && !empty($this -> data['User']['email']) && isset($this -> data['User']['password']) && !empty($this -> data['User']['password'])) {
			$this -> User -> recursive = -1;
			$user = $this -> User -> findByEmail($this -> data['User']['email']);
			if (!empty($user)) {
				if ($user['User']['email_verified']) {
					$this -> Auth -> login($user);
					$this -> redirect($this -> Auth -> redirect());
				} else {
					$this -> Auth -> logout($user);
					$this -> redirect(array('controller' => 'users', 'action' => 'validateEmail'));
				}
			} else {
				$this -> Session -> setFlash($this -> Auth -> loginError, $this -> Auth -> flashElement, array(), 'auth');
			}
		}
	}

	function admin_login() {
		$this -> layout = "ez/login";
		if (isset($this -> data['User']['email']) && !empty($this -> data['User']['email']) && isset($this -> data['User']['password']) && !empty($this -> data['User']['password'])) {
			$this -> User -> recursive = -1;
			$user = $this -> User -> findByEmail($this -> data['User']['email']);
			if (!empty($user)) {
				if ($user['User']['email_verified']) {
					$this -> Auth -> login($user);
					$this -> redirect($this -> Auth -> redirect());
				} else {
					$this -> Auth -> logout($user);
					$this -> redirect(array('controller' => 'users', 'action' => 'validateEmail'));
				}
			} else {
				$this -> Session -> setFlash($this -> Auth -> loginError, $this -> Auth -> flashElement, array(), 'auth');
			}
		}
	}

	function manager_login() {
		$this -> layout = "ez/login";
		if (isset($this -> data['User']['email']) && !empty($this -> data['User']['email']) && isset($this -> data['User']['password']) && !empty($this -> data['User']['password'])) {
			$this -> User -> recursive = -1;
			$user = $this -> User -> findByEmail($this -> data['User']['email']);
			if (!empty($user)) {
				if ($user['User']['email_verified']) {
					$this -> Auth -> login($user);
					$this -> redirect($this -> Auth -> redirect());
				} else {
					$this -> Auth -> logout($user);
					$this -> redirect(array('controller' => 'users', 'action' => 'validateEmail'));
				}
			} else {
				$this -> Session -> setFlash($this -> Auth -> loginError, $this -> Auth -> flashElement, array(), 'auth');
			}
		}
	}

	function ajaxLogin() {
		$this -> User -> recursive = -1;
		$user = $this -> User -> findByEmail($this -> data['User']['email']);
		$email = $user['User']['email'];
		if (!empty($user)) {
			if ($user['User']['email_verified']) {
				if ($this -> Auth -> login($this -> data)) {
					//$user = $this -> User -> read(null, $this -> Auth -> user('id'));
					$user['success'] = true;
					$user['message'] = __('Inicio de sesión exitoso', true);
				} else {
					$user['success'] = false;
					$user['message'] = __('La información no es correcta.', true) . 'Click <a href="/users/resetPassword/' . $email . '>aquí</a>' . __('si quieres resetear tu contraseña.', true);
				}
			} else {
				$user['success'] = false;
				$user['message'] = __('El correo no ha sido verificado', true) . ':: <a href="/users/validateEmail">' . __('Verificar correo', true) . '</a>';
			}
		} else {
			$user['success'] = false;
			$user['message'] = __('No existe un usario registrado con ese correo', true);
		}
		echo json_encode($user);
		$this -> autoRender = false;
		Configure::write('debug', 0);
		exit(0);
	}

	function logout() {
		$this -> redirect($this -> Auth -> logout());
	}

	function owner_logout() {
		$this -> logout();
	}

	function admin_logout() {
		$this -> logout();
	}

	function manager_logout() {
		$this -> logout();
	}

	function encrypt($str, $key) {
		$block = mcrypt_get_block_size('des', 'ecb');
		$pad = $block - (strlen($str) % $block);
		$str .= str_repeat(chr($pad), $pad);

		return mcrypt_encrypt(MCRYPT_DES, $key, $str, MCRYPT_MODE_ECB);
	}

	function decrypt($str, $key) {
		$str = mcrypt_decrypt(MCRYPT_DES, $key, $str, MCRYPT_MODE_ECB);

		$block = mcrypt_get_block_size('des', 'ecb');
		$pad = ord($str[($len = strlen($str)) - 1]);
		return substr($str, 0, strlen($str) - $pad);
	}
	
	function getCode() {
		$id = $this -> Session -> read('Auth.User.id');
		if($id) {
			$code = urlencode($this -> encrypt($id, "\xc8\xd9\xb9\x06\xd9\xe8\xc9\xd2"));
		} else {
			return '';
		}		
	}

}