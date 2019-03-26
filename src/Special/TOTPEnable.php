<?php
/**
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 */

namespace MediaWiki\Extension\OATHAuth\Special;

use MediaWiki\Extension\OATHAuth\Key\TOTPKey;
use MediaWiki\Extension\OATHAuth\OATHUser;
use MediaWiki\Extension\OATHAuth\OATHUserRepository;
use Html;
use HTMLForm;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use FormSpecialPage;
use User;
use Exception;
use MWException;
use ConfigException;
use UserBlockedError;
use UserNotLoggedIn;

/**
 * Special page to display key information to the user
 *
 * @ingroup Extensions
 */
class TOTPEnable extends FormSpecialPage {
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
		$form->getOutput()->addModules( 'ext.oath.totp.showqrcode' );
		$form->getOutput()->addModuleStyles( 'ext.oath.totp.showqrcode.styles' );
	}

	/**
	 * @return string
	 */
	protected function getDisplayFormat() {
		return 'ooui';
	}

	/**
	 * @return bool
	 */
	public function requiresUnblock() {
		return false;
	}

	/**
	 * @param User $user
	 * @throws UserBlockedError
	 * @throws UserNotLoggedIn
	 */
	protected function checkExecutePermissions( User $user ) {
		parent::checkExecutePermissions( $user );

		$this->requireLogin();
	}

	/**
	 * @return array
	 * @throws Exception
	 */
	protected function getFormFields() {
		$key = $this->getRequest()->getSessionData( 'oathauth_totp_key' );

		if ( $key === null ) {
			$key = TOTPKey::newFromRandom();
			$this->getRequest()->setSessionData( 'oathauth_totp_key', $key );
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
					. '<kbd>' . $this->getSecretForDisplay( $key ) . '</kbd><br/>',
				'raw' => true,
				'section' => 'step2',
			],
			'scratchtokens' => [
				'type' => 'info',
				'default' =>
					$this->msg( 'oathauth-scratchtokens' )
					. $this->createResourceList( $this->getScratchTokensForDisplay( $key ) ),
				'raw' => true,
				'section' => 'step3',
			],
			'token' => [
				'type' => 'text',
				'default' => '',
				'label-message' => 'oathauth-entertoken',
				'name' => 'token',
				'section' => 'step4',
				'dir' => 'ltr',
				'autocomplete' => false,
				'spellcheck' => false,
			],
			'returnto' => [
				'type' => 'hidden',
				'default' => $this->getRequest()->getVal( 'returnto' ),
				'name' => 'returnto',
			],
			'returntoquery' => [
				'type' => 'hidden',
				'default' => $this->getRequest()->getVal( 'returntoquery' ),
				'name' => 'returntoquery',
			],
			'module' => [
				'type' => 'hidden',
				'default' => 'totp',
				'name' => 'module'
			]
		];
	}

	/**
	 * @param array $formData
	 * @return array|bool
	 * @throws ConfigException
	 * @throws MWException
	 */
	public function onSubmit( array $formData ) {
		/** @var TOTPKey $key */
		$key = $this->getRequest()->getSessionData( 'oathauth_totp_key' );

		if ( $key->isScratchToken( $formData['token'] ) ) {
			// A scratch token is not allowed for enrollment
			LoggerFactory::getInstance( 'authentication' )->info(
				'OATHAuth {user} attempted to enable 2FA using a scratch token from {clientip}', [
					'user' => $this->getUser()->getName(),
					'clientip' => $this->getRequest()->getIP(),
				]
			);
			return [ 'oathauth-noscratchforvalidation' ];
		}
		if ( !$key->verify( $formData['token'], $this->OATHUser ) ) {
			LoggerFactory::getInstance( 'authentication' )->info(
				'OATHAuth {user} failed to provide a correct token while enabling 2FA from {clientip}', [
					'user' => $this->getUser()->getName(),
					'clientip' => $this->getRequest()->getIP(),
				]
			);
			return [ 'oathauth-failedtovalidateoath' ];
		}

		$auth = MediaWikiServices::getInstance()->getService( 'OATHAuth' );
		$module = $auth->getModuleByKey( 'totp' );
		$this->getRequest()->setSessionData( 'oathauth_totp_key', null );
		$this->OATHUser->setKey( $key );
		$this->OATHUser->setModule( $module );
		$this->OATHRepository->persist( $this->OATHUser, $this->getRequest()->getIP() );

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
			$resourceList .= Html::rawElement( 'li', [], Html::rawElement( 'kbd', [], $resource ) );
		}
		return Html::rawElement( 'ul', [], $resourceList );
	}

	/**
	 * Retrieve the current secret for display purposes
	 *
	 * The characters of the token are split in groups of 4
	 *
	 * @param TOTPKey $key
	 * @return String
	 */
	protected function getSecretForDisplay( TOTPKey $key ) {
		return $this->tokenFormatterFunction( $key->getSecret() );
	}

	/**
	 * Retrieve current scratch tokens for display purposes
	 *
	 * The characters of the token are split in groups of 4
	 *
	 * @param TOTPKey $key
	 * @return string[]
	 */
	protected function getScratchTokensForDisplay( TOTPKey $key ) {
		return array_map( [ $this, 'tokenFormatterFunction' ], $key->getScratchTokens() );
	}

	/**
	 * Formats a key or scratch token by creating groups of 4 separated by space characters
	 *
	 * @param string $token Token to format
	 * @return string The token formatted for display
	 */
	private function tokenFormatterFunction( $token ) {
		return implode( ' ', str_split( $token, 4 ) );
	}
}
