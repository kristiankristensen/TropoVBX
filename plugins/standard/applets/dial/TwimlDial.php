<?php

class TwimlDial {
	/**
	 * For testing only. Some proxies and firewalls 
	 * don't properly pass or set the server name so 
	 * cookies may not set due to a mismatch. Use
	 * this in testing as it will break in load-balanced
	 * server configurations
	 *
	 * @var bool
	 */
	private $use_sessions = false;
	
	static $hangup_stati = array('completed', 'answered');
	static $default_voicemail_message = 'Please leave a message. Press the pound key when you are finished.';
	
	protected $cookie_name;
		
	public $state;
	public $response;
	
	public function __construct(){
		$this->response = new Response();
		
		$this->cookie_name = 'state-'.AppletInstance::getInstanceId();
		
		$this->version = AppletInstance::getValue('version', null);
		$this->callerId = AppletInstance::getValue('callerId', null);

		/* Get current instance	 */
		$this->dial_whom_selector = AppletInstance::getValue('dial-whom-selector');
		$this->dial_whom_user_or_group = AppletInstance::getUserGroupPickerValue('dial-whom-user-or-group');
		$this->dial_whom_number = AppletInstance::getValue('dial-whom-number');

		$this->no_answer_action = AppletInstance::getValue('no-answer-action', 'hangup');
		$this->no_answer_group_voicemail = AppletInstance::getAudioSpeechPickerValue('no-answer-group-voicemail');
		$this->no_answer_redirect = AppletInstance::getDropZoneUrl('no-answer-redirect');
		$this->no_answer_redirect_number = AppletInstance::getDropZoneUrl('no-answer-redirect-number');
	}
	
// Actions
	
	public function dial($user) {
		$dial = $this->response->addDial(array(
									'action' => current_url(), 
									'callerId' => $callerId, 
									'timeout' => 5
								));

		// get users devices and add all active devices to do simultaneous dialing
		if (count($user->devices)) {
			$call_opts = array(
							'url' => site_url('twiml/whisper?name='.urlencode($user->first_name)),
						);
			foreach ($user->devices as $device) {
				if ($device->is_active) {
					if (strpos($device->value, 'client:') !== false) {
						$dial->addClient(str_replace('client:', '', $device->value), $call_opts);
					}
					else {
						$dial->addNumber($device->value, $call_opts);
					}
				}
			}
			return true;
		}
		return false;
	}
	
	/**
	 * Handle nobody picking up the dail
	 *
	 * @return void
	 */
	public function noanswer() {
		$_status = null;
		if ($this->dial_whom_selector == 'number') {
			$this->no_answer_number();
		}
		else {
			$this->no_answer_object();
		}
	}
	
	/**
	 * If the result of a no-answer is to redirect to
	 * a new number we handle that here. If no number just hangup
	 *
	 * @return void
	 */
	protected function no_answer_number() {
		if(empty($this->no_answer_redirect_number)) {
			$this->response->addHangup();
		}

		$this->response->addRedirect($this->no_answer_redirect_number);
	}
	
	/**
	 * If the result of a no-answer is to take a voicemail then
	 * we determine if its a user or group voicemail and then prompt for a record
	 * 
	 * Also, if the result of no-answer is to redirect then that is handled here too.
	 * An empty redirect value will cause a hangup.
	 *
	 * @return void
	 */
	protected function no_answer_object() {
		if ($this->no_answer_action === 'voicemail') {
			switch ($this->dial_whom_instance) {
				case 'VBX_User':
					$voicemail = $this->dial_whom_user_or_group->voicemail;
					break;
				case 'VBX_Group':
					$voicemail = $this->no_answer_group_voicemail;
					break;
				default:
					$voicemail = null;
			}
			
			$this->response->append(AudioSpeechPickerWidget::getVerbForValue($voicemail, new Say(self::$default_voicemail_message)));
			$this->response->addRecord(array('transcribeCallback' => site_url('twiml/transcribe')));
			$this->state = 'recording';
		}
		else if ($this->no_answer_action === 'redirect') {
			if(empty($this->no_answer_redirect)) {
				$this->hangup();
			}
			
			$this->response->addRedirect($this->no_answer_redirect);
		}
		else if ($this->no_answer_action === 'hangup') {
			$this->hangup();
		}
		else {
			trigger_error("Unexpected no_answer_action");
		}
	}
	
	/**
	 * Handle callback after someone leaves a message
	 *
	 * @return void
	 */
	public function add_voice_message() {
		OpenVBX::addVoiceMessage(
							$this->dial_whom_user_or_group,
							$_REQUEST['CallSid'],
							$_REQUEST['From'],
							$_REQUEST['To'],
							$_REQUEST['RecordingUrl'],
							$_REQUEST['RecordingDuration']
						);
	}
	
	/**
	 * Add a hangup to the response
	 *
	 * @return void
	 */
	public function hangup() {
		$this->response->addHangup();
	}
	
	/**
	 * Send the response
	 *
	 * @return void
	 */
	public function respond() {
		$this->response->Respond();
	}

// State

	/**
	 * Figure out our state
	 * 
	 * - First check the dialCallStatus, that'll tell us if we're done or not
	 * - then check our state from the cookie to see if its empty, if so, we're new
	 * - then use the cookie value. this can be a little hairy because '{}' json_decodes as empty...
	 *
	 * @return void
	 */
	public function set_state() {
		$dial_status = isset($_REQUEST['DialCallStatus'])? $_REQUEST['DialCallStatus'] : null;
		$state = $this->_get_state();

		// Process state from cookie
		if (in_array($dial_status, self::$hangup_stati)) {
			$this->state = 'hangup';
		}
		elseif (!$state) {
			$this->state = 'new';
		}
		else {
			// check to see if we need to json_decode
			if (preg_match('|^\{.*?\}$|', $state)) {
				$state = json_decode($state);
				// empty objects don't unserialize to anything, so set an
				// empty array of nothing unserializes from the json
				if (empty($state)) {
					$state = array();
				}
			}
			$this->state = $state;
		}
	}
	
	/**
	 * Get the state from the cookie
	 *
	 * @return string json or std
	 */
	private function _get_state() {
		if ($this->use_sessions) {
			$CI =& get_instance();
			$state = $CI->session->userdata($this->cookie_name);
		}
		else {
			$state = $_COOKIE[$this->cookie_name];
		}

		return $state;
	}
	
	/**
	 * Store the state for use on the next go-around
	 *
	 * @return void
	 */
	public function save_state() {
		$state = $this->state;
		if (is_array($state)) {
			$state = json_encode((object) $state);
		}
		$state = (!empty($state)) ? $state : '{}';
		
		if ($this->use_sessions) {
			$CI =& get_instance();
			$CI->session->set_userdata($this->cookie_name, $state);
		}
		else {
			set_cookie($this->cookie_name, $state, time() + (5 * 60));
		}
	}
}

?>