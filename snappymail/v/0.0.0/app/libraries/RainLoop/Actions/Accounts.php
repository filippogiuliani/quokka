<?php

namespace RainLoop\Actions;

use RainLoop\Enumerations\Capa;
use RainLoop\Exceptions\ClientException;
use RainLoop\Model\Account;
use RainLoop\Model\MainAccount;
use RainLoop\Model\AdditionalAccount;
use RainLoop\Model\Identity;
use RainLoop\Notifications;
use RainLoop\Providers\Identities;
use RainLoop\Providers\Storage\Enumerations\StorageType;
use RainLoop\Utils;
use SnappyMail\IDN;

trait Accounts
{
	private ?\RainLoop\Providers\Identities $oIdentitiesProvider = null;

	protected function GetMainEmail(Account $oAccount)
	{
		return ($oAccount instanceof AdditionalAccount ? $this->getMainAccountFromToken() : $oAccount)->Email();
	}

	public function IdentitiesProvider(): Identities
	{
		if (null === $this->oIdentitiesProvider) {
			$this->oIdentitiesProvider = new Identities($this->fabrica('identities'));
		}

		return $this->oIdentitiesProvider;
	}

	public function GetAccounts(MainAccount $oAccount): array
	{
		if ($this->GetCapa(Capa::ADDITIONAL_ACCOUNTS)) {
			$sAccounts = $this->StorageProvider()->Get($oAccount,
				StorageType::CONFIG,
				'additionalaccounts'
			);
			$aAccounts = $sAccounts ? \json_decode($sAccounts, true)
				: \SnappyMail\Upgrade::ConvertInsecureAccounts($this, $oAccount);
			if ($aAccounts && \is_array($aAccounts)) {
				return $aAccounts;
			}
		}

		return array();
	}

	public function SetAccounts(MainAccount $oAccount, array $aAccounts = array()): void
	{
		$sParentEmail = $oAccount->Email();
		if ($aAccounts) {
			$this->StorageProvider()->Put(
				$oAccount,
				StorageType::CONFIG,
				'additionalaccounts',
				\json_encode($aAccounts)
			);
		} else {
			$this->StorageProvider()->Clear(
				$oAccount,
				StorageType::CONFIG,
				'additionalaccounts'
			);
		}
	}

	/**
	 * Add/Edit additional account
	 * @throws \MailSo\RuntimeException
	 */
	public function DoAccountSetup(): array
	{
		if (!$this->GetCapa(Capa::ADDITIONAL_ACCOUNTS)) {
			return $this->FalseResponse();
		}

		$oMainAccount = $this->getMainAccountFromToken();
		$aAccounts = $this->GetAccounts($oMainAccount);

		$sEmail = IDN::emailToAscii(\trim($this->GetActionParam('email', '')));
		$oPassword = new \SnappyMail\SensitiveString($this->GetActionParam('password', ''));
		$bNew = !empty($this->GetActionParam('new', 1));

		if ($bNew || $oPassword->length()) {
			$oNewAccount = $this->LoginProcess($sEmail, $oPassword, false);
			$sEmail = $oNewAccount->Email();
			$aAccount = $oNewAccount->asTokenArray($oMainAccount);
		} else {
			$aAccount = \RainLoop\Model\AdditionalAccount::convertArray($aAccounts[$sEmail]);
		}

		if ($bNew) {
			if ($oMainAccount->Email() === $sEmail || isset($aAccounts[$sEmail])) {
				throw new ClientException(Notifications::AccountAlreadyExists);
			}
		} else if (!isset($aAccounts[$sEmail])) {
			throw new ClientException(Notifications::AccountDoesNotExist);
		}

		$aAccounts[$sEmail] = $aAccount;

		if ($aAccounts[$sEmail]) {
			$aAccounts[$sEmail]['name'] = \trim($this->GetActionParam('name', ''));
			$this->SetAccounts($oMainAccount, $aAccounts);
		}

		return $this->TrueResponse();
	}

	protected function loadAdditionalAccountImapClient(string $sEmail): \MailSo\Imap\ImapClient
	{
		$sEmail = IDN::emailToAscii($sEmail);
		if (!\strlen($sEmail)) {
			throw new ClientException(Notifications::AccountDoesNotExist);
		}

		$oMainAccount = $this->getMainAccountFromToken();
		$aAccounts = $this->GetAccounts($oMainAccount);
		if (!isset($aAccounts[$sEmail])) {
			throw new ClientException(Notifications::AccountDoesNotExist);
		}
		$oAccount = AdditionalAccount::NewInstanceFromTokenArray($this, $aAccounts[$sEmail]);
		if (!$oAccount) {
			throw new ClientException(Notifications::AccountDoesNotExist);
		}

		$oImapClient = new \MailSo\Imap\ImapClient;
		$oImapClient->SetLogger($this->Logger());
		$this->imapConnect($oAccount, false, $oImapClient);
		return $oImapClient;
	}

	public function DoAccountUnread(): array
	{
		$oImapClient = $this->loadAdditionalAccountImapClient($this->GetActionParam('email', ''));
		$oInfo = $oImapClient->FolderStatus('INBOX');
		return $this->DefaultResponse([
			'unreadEmails' => \max(0, $oInfo->UNSEEN)
		]);
	}

	/**
	 * Imports all mail from AdditionalAccount into MainAccount
	 */
	public function DoAccountImport(): array
	{
		$sEmail = $this->GetActionParam('email', '');
		$oImapSource = $this->loadAdditionalAccountImapClient($sEmail);

		$oMainAccount = $this->getMainAccountFromToken();
		$oImapTarget = new \MailSo\Imap\ImapClient;
		$oImapTarget->SetLogger($this->Logger());
		$this->imapConnect($oMainAccount, false, $oImapTarget);

		$oSync = new \SnappyMail\Imap\Sync;
		$oSync->oImapSource = $oImapSource;
		$oSync->oImapTarget = $oImapTarget;

		$rootfolder = $this->GetActionParam('rootfolder', '') ?: $sEmail;
		$oSync->import($rootfolder);
		exit;
	}

	/**
	 * Quokka: make an added account the main one (the first in the list). The added accounts' passwords are sealed with
	 * the main account's key: they are opened with the old one and sealed again with the new one, the old main account
	 * joins the list, and plugins hear of it (Pocket ID then opens the new main account).
	 * @throws \MailSo\RuntimeException
	 */
	public function DoAccountMakeMain(): array
	{
		if (!$this->GetCapa(Capa::ADDITIONAL_ACCOUNTS)) {
			return $this->FalseResponse();
		}
		$oOldMain = $this->getMainAccountFromToken();
		$aAccounts = $this->GetAccounts($oOldMain);
		$sEmail = IDN::emailToAscii(\trim($this->GetActionParam('email', '')));
		if (!isset($aAccounts[$sEmail])) {
			throw new ClientException(Notifications::AccountDoesNotExist);
		}
		// the order wanted: the new main account first, then the others (the old main account among them)
		$aOrder = \array_values(\array_filter(\array_map(
			fn($e) => IDN::emailToAscii(\trim((string) $e)),
			(array) $this->GetActionParam('order', [])
		)));

		// open every password while the old key is still the one in use
		$aOpen = [];
		foreach ($aAccounts as $sKey => $aAccount) {
			$oAccount = AdditionalAccount::NewInstanceFromTokenArray($this, $aAccount, true);
			if (!$oAccount || !\strlen($oAccount->ImapPass())) {
				throw new ClientException(Notifications::AccountSwitchFailed);
			}
			$aOpen[$sKey] = [$oAccount, $aAccount['name'] ?? ''];
		}
		// names: the old main account's is on its identity, the new one's on its row in the list
		$fPrimary = fn(Account $o) => \current(\array_filter($this->IdentitiesProvider()->GetIdentities($o, true),
			fn($i) => $i->IsAccountIdentities())) ?: null;
		$sOldName = $fPrimary($oOldMain)?->Name() ?? '';
		$sNewName = $aOpen[$sEmail][1];
		// the old main account as an added one: same data, its password already checked by this session
		$oOldAsAdded = AdditionalAccount::NewInstanceFromTokenArray($this, $oOldMain->jsonSerialize(), true);
		if (!$oOldAsAdded || !\strlen($oOldAsAdded->ImapPass())) {
			throw new ClientException(Notifications::AccountSwitchFailed);
		}
		$bSignMe = !empty($_COOKIE[self::AUTH_SIGN_ME_TOKEN_KEY]);

		// sign in to the new one as the main account (its password checked by the mail server, second step included)
		$oNewPassword = new \SnappyMail\SensitiveString($aOpen[$sEmail][0]->ImapPass());
		$oNewMain = $this->LoginProcess($sEmail, $oNewPassword);

		$oIdentity = $fPrimary($oNewMain);
		if ($oIdentity && '' === $oIdentity->Name() && '' !== $sNewName) {
			$oIdentity->SetName($sNewName);
			$this->IdentitiesProvider()->UpdateIdentity($oNewMain, $oIdentity);
		}
		$aOpen[$oOldMain->Email()] = [$oOldAsAdded, $sOldName];
		unset($aOpen[$sEmail]);
		$aKeys = \array_values(\array_unique(\array_merge(
			\array_values(\array_intersect($aOrder, \array_keys($aOpen))),
			[$oOldMain->Email()],
			\array_keys($aOpen)
		)));
		$aNew = [];
		foreach ($aKeys as $sKey) {
			[$oAccount, $sName] = $aOpen[$sKey];
			$aNew[$sKey] = $oAccount->asTokenArray($oNewMain);
			$aNew[$sKey]['name'] = $sName;
		}
		$this->SetAccounts($oNewMain, $aNew);
		$this->SetAccounts($oOldMain, []);
		$bSignMe && $this->SetSignMeToken($oNewMain);
		$this->Plugins()->RunHook('quokka.main-account', array($oOldMain->Email(), $oNewMain, $oNewPassword));

		return $this->TrueResponse(['Reload' => true]);
	}

	/**
	 * @throws \MailSo\RuntimeException
	 */
	public function DoAccountDelete(): array
	{
		$oMainAccount = $this->getMainAccountFromToken();

		if (!$this->GetCapa(Capa::ADDITIONAL_ACCOUNTS)) {
			return $this->FalseResponse();
		}

		$sEmailToDelete = IDN::emailToAscii(\trim($this->GetActionParam('emailToDelete', '')));

		$aAccounts = $this->GetAccounts($oMainAccount);

		if (\strlen($sEmailToDelete) && isset($aAccounts[$sEmailToDelete])) {
			$bReload = false;
			$oAccount = $this->getAccountFromToken();
			if ($oAccount instanceof AdditionalAccount && $oAccount->Email() === $sEmailToDelete) {
//				$this->SetAdditionalAuthToken(null);
				\SnappyMail\Cookies::clear(self::AUTH_ADDITIONAL_TOKEN_KEY);
				$bReload = true;
			}

			unset($aAccounts[$sEmailToDelete]);
			$this->SetAccounts($oMainAccount, $aAccounts);

			return $this->TrueResponse(array('Reload' => $bReload));
		}

		return $this->FalseResponse();
	}

	public function getAccountData(Account $oAccount): array
	{
		$oConfig = $this->Config();
		$minRefreshInterval = (int) $oConfig->Get('webmail', 'min_refresh_interval', 5);
		$aResult = [
//			'Email' => IDN::emailToUtf8($oAccount->Email()),
			'Email' => $oAccount->Email(),
			'accountHash' => $oAccount->Hash(),
			'mainEmail' => \RainLoop\Api::Actions()->getMainAccountFromToken()->Email(),
			'contactsAllowed' => $this->AddressBookProvider($oAccount)->IsActive(),
			'HideUnsubscribed' => false,
			'defaultSort' => '',
			'useThreads' => (bool) $oConfig->Get('defaults', 'mail_use_threads', false),
			'threadAlgorithm' => '',
			'ReplySameFolder' => (bool) $oConfig->Get('defaults', 'mail_reply_same_folder', false),
			'HideDeleted' => true,
			'ShowUnreadCount' => false,
			'UnhideKolabFolders' => false,
			// Quokka: every minute for every account (the notifications with the app closed come from the sorter)
			'CheckMailInterval' => \max(1, $minRefreshInterval)
		];
		$oSettingsLocal = $this->SettingsProvider(true)->Load($oAccount);
		if ($oSettingsLocal instanceof \RainLoop\Settings) {
			$aResult['SentFolder'] = (string) $oSettingsLocal->GetConf('SentFolder', '');
			$aResult['DraftsFolder'] = (string) $oSettingsLocal->GetConf('DraftsFolder', '');
			$aResult['JunkFolder'] = (string) $oSettingsLocal->GetConf('JunkFolder', '');
			$aResult['TrashFolder'] = (string) $oSettingsLocal->GetConf('TrashFolder', '');
			$aResult['ArchiveFolder'] = (string) $oSettingsLocal->GetConf('ArchiveFolder', '');
			$aResult['HideUnsubscribed'] = (bool) $oSettingsLocal->GetConf('HideUnsubscribed', $aResult['HideUnsubscribed']);
			$aResult['defaultSort'] = (string) $oSettingsLocal->GetConf('defaultSort', $aResult['defaultSort']);
			$aResult['useThreads'] = (bool) $oSettingsLocal->GetConf('UseThreads', $aResult['useThreads']);
			$aResult['threadAlgorithm'] = (string) $oSettingsLocal->GetConf('threadAlgorithm', $aResult['threadAlgorithm']);
			$aResult['ReplySameFolder'] = (bool) $oSettingsLocal->GetConf('ReplySameFolder', $aResult['ReplySameFolder']);
			$aResult['HideDeleted'] = (bool)$oSettingsLocal->GetConf('HideDeleted', $aResult['HideDeleted']);
			$aResult['ShowUnreadCount'] = (bool)$oSettingsLocal->GetConf('ShowUnreadCount', $aResult['ShowUnreadCount']);
			$aResult['UnhideKolabFolders'] = (bool)$oSettingsLocal->GetConf('UnhideKolabFolders', $aResult['UnhideKolabFolders']);
			// Quokka: not a choice any more, the saved value is left aside
/*
			foreach ($oSettingsLocal->toArray() as $key => $value) {
				$aResult[\lcfirst($key)] = $value;
			}
			$aResult['junkFolder'] = $aResult['spamFolder'];
			unset($aResult['checkableFolder']);
			unset($aResult['theme']);
*/
		}
		return $aResult;
	}

	/**
	 * @throws \MailSo\RuntimeException
	 */
	public function DoAccountSwitch(): array
	{
		if ($this->switchAccount(new \MailSo\Mime\Email($this->GetActionParam('Email', '')))) {
			$oAccount = $this->getAccountFromToken();
			$aResult = $this->getAccountData($oAccount);
//			$this->Plugins()->InitAppData($bAdmin, $aResult, $oAccount);
			return $this->DefaultResponse($aResult);
		}
		return $this->FalseResponse();
	}

	/**
	 * @throws \MailSo\RuntimeException
	 */
	public function DoIdentityUpdate(): array
	{
		$oAccount = $this->getAccountFromToken();

		$oIdentity = new Identity();
		if (!$oIdentity->FromJSON($this->GetActionParams(), true)) {
			throw new ClientException(Notifications::InvalidInputArgument);
		}
/*		// TODO: verify private key for certificate?
		if ($oIdentity->smimeCertificate && $oIdentity->smimeKey) {
			new \SnappyMail\SMime\Certificate($oIdentity->smimeCertificate, $oIdentity->smimeKey);
		}
*/
		$this->IdentitiesProvider()->UpdateIdentity($oAccount, $oIdentity);
		return $this->TrueResponse();
	}

	/**
	 * @throws \MailSo\RuntimeException
	 */
	public function DoIdentityDelete(): array
	{
		$oAccount = $this->getAccountFromToken();

		if (!$this->GetCapa(Capa::IDENTITIES)) {
			return $this->FalseResponse();
		}

		$sId = \trim($this->GetActionParam('idToDelete', ''));
		if (empty($sId)) {
			throw new ClientException(Notifications::UnknownError);
		}

		$this->IdentitiesProvider()->DeleteIdentity($oAccount, $sId);
		return $this->TrueResponse();
	}

	/**
	 * @throws \MailSo\RuntimeException
	 */
	public function DoAccountsAndIdentitiesSortOrder(): array
	{
		$aAccounts = $this->GetActionParam('Accounts', null);
		$aIdentities = $this->GetActionParam('Identities', null);

		if (!\is_array($aAccounts) && !\is_array($aIdentities)) {
			return $this->FalseResponse();
		}

		if (\is_array($aAccounts) && 1 < \count($aAccounts)) {
			$oAccount = $this->getMainAccountFromToken();
			$aAccounts = \array_filter(\array_merge(
				\array_fill_keys($aAccounts, null),
				$this->GetAccounts($oAccount)
			));
			$this->SetAccounts($oAccount, $aAccounts);
		}

		return $this->DefaultResponse($this->LocalStorageProvider()->Put(
			$this->getAccountFromToken(),
			StorageType::CONFIG,
			'identities_order',
			\json_encode(array(
				'Identities' => \is_array($aIdentities) ? $aIdentities : array()
			))
		));
	}

	/**
	 * @throws \MailSo\RuntimeException
	 */
	public function DoAccountsAndIdentities(): array
	{
		// https://github.com/the-djmaze/snappymail/issues/571
		// Quokka: the main account is labelled with the name of its own main identity (added accounts have a
		// name of their own), read from the main account, not from the one you are currently in
		$sMainName = '';
		$oMainAccount = $this->getMainAccountFromToken();
		foreach ($this->IdentitiesProvider()->GetIdentities($oMainAccount, false) as $oIdentity) {
			if ($oIdentity->IsAccountIdentities()) {
				$sMainName = $oIdentity->Name();
				break;
			}
		}
		return $this->DefaultResponse(array(
			'mainIdentityName' => $sMainName,
			'Accounts' => \array_values(\array_map(function($value){
					return [
						'email' => IDN::emailToUtf8($value['email'] ?? $value[1]),
						'name' => $value['name'] ?? ''
					];
				},
				$this->GetAccounts($this->getMainAccountFromToken())
			)),
			'Identities' => $this->GetIdentities($this->getAccountFromToken())
		));
	}

	/**
	 * @return Identity[]
	 */
	public function GetIdentities(Account $oAccount): array
	{
		// A custom name for a single identity is also stored in this system
		$allowMultipleIdentities = $this->GetCapa(Capa::IDENTITIES);

		// Get all identities
		$identities = $this->IdentitiesProvider()->GetIdentities($oAccount, $allowMultipleIdentities);

		// Sort identities
		$orderString = $this->LocalStorageProvider()->Get($oAccount, StorageType::CONFIG, 'identities_order');
		$old = false;
		if (!$orderString) {
			$orderString = $this->StorageProvider()->Get($oAccount, StorageType::CONFIG, 'accounts_identities_order');
			$old = !!$orderString;
		}

		$order = \json_decode($orderString, true) ?? [];
		if (isset($order['Identities']) && \is_array($order['Identities']) && 1 < \count($order['Identities'])) {
			$list = \array_map(function ($item) {
				return ('' === $item) ? '---' : $item;
			}, $order['Identities']);

			\usort($identities, function ($a, $b) use ($list) {
				return \array_search($a->Id(true), $list) < \array_search($b->Id(true), $list) ? -1 : 1;
			});
		}

		if ($old) {
			$this->LocalStorageProvider()->Put(
				$oAccount,
				StorageType::CONFIG,
				'identities_order',
				\json_encode(array('Identities' => empty($order['Identities']) ? [] : $order['Identities']))
			);
			$this->StorageProvider()->Clear($oAccount, StorageType::CONFIG, 'accounts_identities_order');
		}

		return $identities;
	}

	public function GetIdentityByID(Account $oAccount, string $sID, bool $bFirstOnEmpty = false): ?Identity
	{
		$aIdentities = $this->GetIdentities($oAccount);

		foreach ($aIdentities as $oIdentity) {
			if ($oIdentity && $sID === $oIdentity->Id()) {
				return $oIdentity;
			}
		}

		return $bFirstOnEmpty && isset($aIdentities[0]) ? $aIdentities[0] : null;
	}

}
