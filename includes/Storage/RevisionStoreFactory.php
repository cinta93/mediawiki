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
 *
 * Attribution notice: when this file was created, much of its content was taken
 * from the Revision.php file as present in release 1.30. Refer to the history
 * of that file for original authorship.
 *
 * @file
 */

namespace MediaWiki\Storage;

use ActorMigration;
use CommentStore;
use MediaWiki\Logger\Spi as LoggerSpi;
use WANObjectCache;
use Wikimedia\Assert\Assert;
use Wikimedia\Rdbms\ILBFactory;

/**
 * Factory service for RevisionStore instances. This allows RevisionStores to be created for
 * cross-wiki access.
 *
 * @warning Beware compatibility issues with schema migration in the context of cross-wiki access!
 * This class assumes that all wikis are at compatible migration stages for all relevant schemas.
 * Relevant schemas are: revision storage (MCR), the revision comment table, and the actor table.
 * Migration stages are compatible as long as a) there are no wikis in the cluster that only write
 * the old schema or b) there are no wikis that read only the new schema.
 *
 * @since 1.32
 */
class RevisionStoreFactory {

	/** @var BlobStoreFactory */
	private $blobStoreFactory;
	/** @var ILBFactory */
	private $dbLoadBalancerFactory;
	/** @var WANObjectCache */
	private $cache;
	/** @var LoggerSpi */
	private $loggerProvider;

	/** @var CommentStore */
	private $commentStore;
	/** @var ActorMigration */
	private $actorMigration;
	/** @var int One of the MIGRATION_* constants */
	private $mcrMigrationStage;
	/**
	 * @var bool
	 * @see $wgContentHandlerUseDB
	 */
	private $contentHandlerUseDB;

	/**
	 * @param ILBFactory $dbLoadBalancerFactory
	 * @param BlobStoreFactory $blobStoreFactory
	 * @param WANObjectCache $cache
	 * @param CommentStore $commentStore
	 * @param ActorMigration $actorMigration
	 * @param int $migrationStage
	 * @param LoggerSpi $loggerProvider
	 * @param bool $contentHandlerUseDB see {@link $wgContentHandlerUseDB}. Must be the same
	 *        for all wikis in the cluster. Will go away after MCR migration.
	 */
	public function __construct(
		ILBFactory $dbLoadBalancerFactory,
		BlobStoreFactory $blobStoreFactory,
		WANObjectCache $cache,
		CommentStore $commentStore,
		ActorMigration $actorMigration,
		$migrationStage,
		LoggerSpi $loggerProvider,
		$contentHandlerUseDB
	) {
		Assert::parameterType( 'integer', $migrationStage, '$migrationStage' );
		$this->dbLoadBalancerFactory = $dbLoadBalancerFactory;
		$this->blobStoreFactory = $blobStoreFactory;
		$this->cache = $cache;
		$this->commentStore = $commentStore;
		$this->actorMigration = $actorMigration;
		$this->mcrMigrationStage = $migrationStage;
		$this->loggerProvider = $loggerProvider;
		$this->contentHandlerUseDB = $contentHandlerUseDB;
	}
	/**

	/**
	 * @since 1.32
	 *
	 * @param bool|string $wikiId false for the current domain / wikid
	 *
	 * @return RevisionStore for the given wikiId with all necessary services and a logger
	 */
	public function getRevisionStore( $wikiId = false ) {
		Assert::parameterType( 'string|boolean', $wikiId, '$wikiId' );

		$store = new RevisionStore(
			$this->dbLoadBalancerFactory->getMainLB( $wikiId ),
			$this->blobStoreFactory->newSqlBlobStore( $wikiId ),
			$this->cache, // Pass local cache instance; Leave cache sharing to RevisionStore.
			$this->commentStore,
			$this->getContentModelStore( $wikiId ),
			$this->getSlotRoleStore( $wikiId ),
			$this->mcrMigrationStage,
			$this->actorMigration,
			$wikiId
		);

		$store->setLogger( $this->loggerProvider->getLogger( 'RevisionStore' ) );
		$store->setContentHandlerUseDB( $this->contentHandlerUseDB );

		return $store;
	}

	/**
	 * @param string $wikiId
	 * @return NameTableStore
	 */
	private function getContentModelStore( $wikiId ) {
		// XXX: a dedicated ContentModelStore subclass would avoid hard-coding
		// knowledge about the schema here.
		return new NameTableStore(
			$this->dbLoadBalancerFactory->getMainLB( $wikiId ),
			$this->cache, // Pass local cache instance; Leave cache sharing to NameTableStore.
			$this->loggerProvider->getLogger( 'NameTableSqlStore' ),
			'content_models',
			'model_id',
			'model_name',
			null,
			$wikiId
		);
	}

	/**
	 * @param string $wikiId
	 * @return NameTableStore
	 */
	private function getSlotRoleStore( $wikiId ) {
		// XXX: a dedicated ContentModelStore subclass would avoid hard-coding
		// knowledge about the schema here.
		return new NameTableStore(
			$this->dbLoadBalancerFactory->getMainLB( $wikiId ),
			$this->cache, // Pass local cache instance; Leave cache sharing to NameTableStore.
			$this->loggerProvider->getLogger( 'NameTableSqlStore' ),
			'slot_roles',
			'role_id',
			'role_name',
			'strtolower',
			$wikiId
		);
	}

}
