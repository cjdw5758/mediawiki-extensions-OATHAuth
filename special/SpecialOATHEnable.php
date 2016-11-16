<?php

/**
 * Special page to display key information to the user
 *
 * @ingroup Extensions
 */
class SpecialOATHEnable extends FormSpecialPage {
	/** @var OATHUserRepository */
	private $OATHRepository;

	/** @var OATHUser */
	private $OATHUser;

	/**
	 * Initialize the OATH user based on the current local User object in the context
	 *
	 * @param OATHUserRepository $repository
	 * @param OATHUser $user
	 */
	public function __construct( OATHUserRepository $repository, OATHUser $user ) {
		parent::__construct( 'OATH', 'oathauth-enable', false );

		$this->OATHRepository = $repository;
		$this->OATHUser = $user;
	}

	public function doesWrites() {
		return true;
	}

	/**
	 * Set the page title and add JavaScript RL modules
	 *
	 * @param HTMLForm $form
	 */
	public function alterForm( HTMLForm $form ) {
		$form->setMessagePrefix( 'oathauth' );
		$form->setWrapperLegend( false );
		$form->getOutput()->setPageTitle( $this->msg( 'oathauth-enable' ) );
		$form->getOutput()->addModules( 'ext.oath.showqrcode' );
		$form->getOutput()->addModuleStyles( 'ext.oath.showqrcode.styles' );
	}

	/**
	 * @return string
	 */
	protected function getDisplayFormat() {
		return 'vform';
	}

	/**
	 * @return bool
	 */
	public function requiresUnblock() {
		return false;
	}

	/**
	 * Require users to be logged in
	 *
	 * @param User $user
	 *
	 * @return bool|void
	 */
	protected function checkExecutePermissions( User $user ) {
		parent::checkExecutePermissions( $user );

		$this->requireLogin();
	}

	/**
	 * @return array[]
	 */
	protected function getFormFields() {
		$key = $this->getRequest()->getSessionData( 'oathauth_key' );

		if ( $key === null ) {
			$key = OATHAuthKey::newFromRandom();
			$this->getRequest()->setSessionData( 'oathauth_key', $key );
		}

		$secret = $key->getSecret();
		$label = "{$this->OATHUser->getIssuer()}:{$this->OATHUser->getAccount()}";
		$qrcodeUrl = "otpauth://totp/"
			. rawurlencode( $label )
			. "?secret="
			. rawurlencode( $secret )
			. "&issuer="
			. rawurlencode( $this->OATHUser->getIssuer() );

		$qrcodeElement = Html::element( 'div', [
			'data-mw-qrcode-url' => $qrcodeUrl,
			'class' => 'mw-display-qrcode',
			// Include width/height, so js won't re-arrange layout
			// And non-js users will have this hidden with CSS
			'style' => 'width: 256px; height: 256px;'
		] );

		return [
			'app' => [
				'type' => 'info',
				'default' => $this->msg( 'oathauth-step1-test' )->escaped(),
				'raw' => true,
				'section' => 'step1',
			],
			'qrcode' => [
				'type' => 'info',
				'default' => $qrcodeElement,
				'raw' => true,
				'section' => 'step2',
			],
			'manual' => [
				'type' => 'info',
				'label-message' => 'oathauth-step2alt',
				'default' =>
					'<strong>' . $this->msg( 'oathauth-account' )->escaped() . '</strong><br/>'
					. $this->OATHUser->getAccount() . '<br/><br/>'
					. '<strong>' . $this->msg( 'oathauth-secret' )->escaped() . '</strong><br/>'
					. $key->getSecret() . '<br/>',
				'raw' => true,
				'section' => 'step2',
			],
			'scratchtokens' => [
				'type' => 'info',
				'default' =>
					$this->msg( 'oathauth-scratchtokens' )
					. $this->createResourceList( $key->getScratchTokens() ),
				'raw' => true,
				'section' => 'step3',
			],
			'token' => [
				'type' => 'text',
				'default' => '',
				'label-message' => 'oathauth-entertoken',
				'name' => 'token',
				'section' => 'step4',
			],
			'returnto' => [
				'type' => 'hidden',
				'default' => $this->getRequest()->getVal( 'returnto' ),
				'name' => 'returnto',
			],
			'returntoquery' => [
				'type' => 'hidden',
				'default' => $this->getRequest()->getVal( 'returntoquery' ),
				'name' => 'returntoquery', ]
		];
	}

	/**
	 * @param array $formData
	 *
	 * @return array|bool
	 */
	public function onSubmit( array $formData ) {
		/** @var OATHAuthKey $key */
		$key = $this->getRequest()->getSessionData( 'oathauth_key' );

		if ( !$key->verifyToken( $formData['token'], $this->OATHUser ) ) {
			return [ 'oathauth-failedtovalidateoauth' ];
		}

		$this->getRequest()->setSessionData( 'oathauth_key', null );
		$this->OATHUser->setKey( $key );
		$this->OATHRepository->persist( $this->OATHUser );

		return true;
	}

	public function onSuccess() {
		$this->getOutput()->addWikiMsg( 'oathauth-validatedoath' );
		$this->getOutput()->returnToMain();
	}

	/**
	 * @param $resources array
	 * @return string
	 */
	private function createResourceList( $resources ) {
		$resourceList = '';
		foreach ( $resources as $resource ) {
			$resourceList .= Html::rawElement( 'li', [], $resource );
		}
		return Html::rawElement( 'ul', [], $resourceList );
	}
}
