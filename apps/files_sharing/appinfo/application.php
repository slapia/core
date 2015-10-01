<?php
/**
 * @author Morris Jobke <hey@morrisjobke.de>
 * @author Robin Appelman <icewind@owncloud.com>
 * @author Roeland Jago Douma <roeland@famdouma.nl>
 *
 * @copyright Copyright (c) 2015, ownCloud, Inc.
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 */

namespace OCA\Files_Sharing\AppInfo;

use OCA\Files_Sharing\Helper;
use OCA\Files_Sharing\MountProvider;
use OCA\Files_Sharing\Propagation\PropagationManager;
use OCP\AppFramework\App;
use OC\AppFramework\Utility\SimpleContainer;
use OCA\Files_Sharing\Controllers\ExternalSharesController;
use OCA\Files_Sharing\Controllers\ShareController;
use OCA\Files_Sharing\Middleware\SharingCheckMiddleware;
use \OCP\IContainer;
use OCA\Files_Sharing\Capabilities;

class Application extends App {
	public function __construct(array $urlParams = array()) {
		parent::__construct('files_sharing', $urlParams);

		$container = $this->getContainer();
		$server = $container->getServer();

		/**
		 * Controllers
		 */
		$container->registerService('ShareController', function (SimpleContainer $c) use ($server) {
			return new ShareController(
				$c->query('AppName'),
				$c->query('Request'),
				$c->query('UserSession'),
				$server->getAppConfig(),
				$server->getConfig(),
				$c->query('URLGenerator'),
				$c->query('UserManager'),
				$server->getLogger(),
				$server->getActivityManager()
			);
		});
		$container->registerService('ExternalSharesController', function (SimpleContainer $c) {
			return new ExternalSharesController(
				$c->query('AppName'),
				$c->query('Request'),
				$c->query('IsIncomingShareEnabled'),
				$c->query('ExternalManager'),
				$c->query('HttpClientService')
			);
		});

		/**
		 * Core class wrappers
		 */
		$container->registerService('UserSession', function (SimpleContainer $c) use ($server) {
			return $server->getUserSession();
		});
		$container->registerService('URLGenerator', function (SimpleContainer $c) use ($server) {
			return $server->getUrlGenerator();
		});
		$container->registerService('UserManager', function (SimpleContainer $c) use ($server) {
			return $server->getUserManager();
		});
		$container->registerService('HttpClientService', function (SimpleContainer $c) use ($server) {
			return $server->getHTTPClientService();
		});
		$container->registerService('IsIncomingShareEnabled', function (SimpleContainer $c) {
			return Helper::isIncomingServer2serverShareEnabled();
		});
		$container->registerService('ExternalManager', function (SimpleContainer $c) use ($server) {
			$user = $server->getUserSession()->getUser();
			$uid = $user ? $user->getUID() : null;
			return new \OCA\Files_Sharing\External\Manager(
				$server->getDatabaseConnection(),
				\OC\Files\Filesystem::getMountManager(),
				\OC\Files\Filesystem::getLoader(),
				$server->getHTTPHelper(),
				$server->getNotificationManager(),
				$uid
			);
		});

		/**
		 * Middleware
		 */
		$container->registerService('SharingCheckMiddleware', function (SimpleContainer $c) use ($server) {
			return new SharingCheckMiddleware(
				$c->query('AppName'),
				$server->getConfig(),
				$server->getAppManager()
			);
		});

		// Execute middlewares
		$container->registerMiddleware('SharingCheckMiddleware');

		$container->registerService('MountProvider', function (IContainer $c) {
			/** @var \OCP\IServerContainer $server */
			$server = $c->query('ServerContainer');
			return new MountProvider(
				$server->getConfig(),
				$c->query('PropagationManager')
			);
		});

		$container->registerService('PropagationManager', function (IContainer $c) {
			/** @var \OCP\IServerContainer $server */
			$server = $c->query('ServerContainer');
			return new PropagationManager(
				$server->getUserSession(),
				$server->getConfig()
			);
		});

		/*
		 * Register capabilities
		 */
		$container->registerCapability('OCA\Files_Sharing\Capabilities');
	}

	public function registerMountProviders() {
		/** @var \OCP\IServerContainer $server */
		$server = $this->getContainer()->query('ServerContainer');
		$mountProviderCollection = $server->getMountProviderCollection();
		$mountProviderCollection->registerProvider($this->getContainer()->query('MountProvider'));
	}

	public function setupPropagation() {
		$propagationManager = $this->getContainer()->query('PropagationManager');
		\OCP\Util::connectHook('OC_Filesystem', 'setup', $propagationManager, 'globalSetup');
		$groupManager = $this->getContainer()->getServer()->getGroupManager();

		$mountProvider = $this->getContainer()->query('MountProvider');
		$sharesBefore = [];

		$groupManager->listen('\OC\Group', 'preAddUser', function (\OCP\IGroup $group, \OCP\IUser $user) use ($propagationManager, $sharesBefore) {
			$sharesBefore = \OCP\Share::getItemsSharedWithUser('file', $user->getUID());
		});
		$groupManager->listen('\OC\Group', 'postAddUser', function (\OCP\IGroup $group, \OCP\IUser $user) use ($propagationManager, $sharesBefore) {
			$sharesAfter = \OCP\Share::getItemsSharedWithUser('file', $user->getUID());

			$newShares = array_udiff(
				$sharesAfter,
				$sharesBefore,
				function($share1, $share2) {
					return ($share2['id'] - $share1['id']);
				}
			);

			$propagationManager->propagateSharesToUser($newShares, $user->getUID());
		});
	}
}
