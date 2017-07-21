<?php
/**
 * Implements Special:Import
 *
 * Copyright © 2003,2005 Brion Vibber <brion@pobox.com>
 * https://www.mediawiki.org/
 *
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
 * @file
 * @ingroup SpecialPage
 */

use MediaWiki\MediaWikiServices;

/**
 * MediaWiki page data importer
 *
 * @ingroup SpecialPage
 */
class SpecialImport extends SpecialPage {
	private $sourceName = false;
	private $interwiki = false;
	private $subproject;
	private $fullInterwikiPrefix;
	private $mapping = 'default';
	private $namespace;
	private $rootpage = '';
	private $frompage = '';
	private $logcomment = false;
	private $history = true;
	private $includeTemplates = false;
	private $pageLinkDepth;
	private $importSources;

	public function __construct() {
		parent::__construct( 'Import', 'import' );
	}

	public function doesWrites() {
		return true;
	}

	/**
	 * Execute
	 * @param string|null $par
	 * @throws PermissionsError
	 * @throws ReadOnlyError
	 */
	function execute( $par ) {
		$this->useTransactionalTimeLimit();

		$this->setHeaders();
		$this->outputHeader();

		$this->namespace = $this->getConfig()->get( 'ImportTargetNamespace' );

		$this->getOutput()->addModules( 'mediawiki.special.import' );

		$this->importSources = $this->getConfig()->get( 'ImportSources' );
		Hooks::run( 'ImportSources', [ &$this->importSources ] );

		$user = $this->getUser();
		if ( !$user->isAllowedAny( 'import', 'importupload' ) ) {
			throw new PermissionsError( 'import' );
		}

		# @todo Allow Title::getUserPermissionsErrors() to take an array
		# @todo FIXME: Title::checkSpecialsAndNSPermissions() has a very wierd expectation of what
		# getUserPermissionsErrors() might actually be used for, hence the 'ns-specialprotected'
		$errors = wfMergeErrorArrays(
			$this->getPageTitle()->getUserPermissionsErrors(
				'import', $user, true,
				[ 'ns-specialprotected', 'badaccess-group0', 'badaccess-groups' ]
			),
			$this->getPageTitle()->getUserPermissionsErrors(
				'importupload', $user, true,
				[ 'ns-specialprotected', 'badaccess-group0', 'badaccess-groups' ]
			)
		);

		if ( $errors ) {
			throw new PermissionsError( 'import', $errors );
		}

		$this->checkReadOnly();

		$request = $this->getRequest();
		if ( $request->wasPosted() && $request->getVal( 'action' ) == 'submit' ) {
			$this->doImport();
		}
		$this->showForm();
	}

	/**
	 * Do the actual import
	 */
	private function doImport() {
		$isUpload = false;
		$request = $this->getRequest();
		$this->sourceName = $request->getVal( "source" );

		$this->logcomment = $request->getText( 'log-comment' );
		$this->pageLinkDepth = $this->getConfig()->get( 'ExportMaxLinkDepth' ) == 0
			? 0
			: $request->getIntOrNull( 'pagelink-depth' );

		$this->mapping = $request->getVal( 'mapping' );
		if ( $this->mapping === 'namespace' ) {
			$this->namespace = $request->getIntOrNull( 'namespace' );
		} elseif ( $this->mapping === 'subpage' ) {
			$this->rootpage = $request->getText( 'rootpage' );
		} else {
			$this->mapping = 'default';
		}

		$user = $this->getUser();
		if ( !$user->matchEditToken( $request->getVal( 'editToken' ) ) ) {
			$source = Status::newFatal( 'import-token-mismatch' );
		} elseif ( $this->sourceName === 'upload' ) {
			$isUpload = true;
			if ( $user->isAllowed( 'importupload' ) ) {
				$source = ImportStreamSource::newFromUpload( "xmlimport" );
			} else {
				throw new PermissionsError( 'importupload' );
			}
		} elseif ( $this->sourceName === 'interwiki' ) {
			if ( !$user->isAllowed( 'import' ) ) {
				throw new PermissionsError( 'import' );
			}
			$this->interwiki = $this->fullInterwikiPrefix = $request->getVal( 'interwiki' );
			// does this interwiki have subprojects?
			$hasSubprojects = array_key_exists( $this->interwiki, $this->importSources );
			if ( !$hasSubprojects && !in_array( $this->interwiki, $this->importSources ) ) {
				$source = Status::newFatal( "import-invalid-interwiki" );
			} else {
				if ( $hasSubprojects ) {
					$this->subproject = $request->getVal( 'subproject' );
					$this->fullInterwikiPrefix .= ':' . $request->getVal( 'subproject' );
				}
				if ( $hasSubprojects &&
					!in_array( $this->subproject, $this->importSources[$this->interwiki] )
				) {
					$source = Status::newFatal( "import-invalid-interwiki" );
				} else {
					$this->history = $request->getCheck( 'interwikiHistory' );
					$this->frompage = $request->getText( "frompage" );
					$this->includeTemplates = $request->getCheck( 'interwikiTemplates' );
					$source = ImportStreamSource::newFromInterwiki(
						$this->fullInterwikiPrefix,
						$this->frompage,
						$this->history,
						$this->includeTemplates,
						$this->pageLinkDepth );
				}
			}
		} else {
			$source = Status::newFatal( "importunknownsource" );
		}

		$out = $this->getOutput();
		if ( !$source->isGood() ) {
			$out->wrapWikiMsg(
				"<p class=\"error\">\n$1\n</p>",
				[ 'importfailed', $source->getWikiText() ]
			);
		} else {
			$importer = new WikiImporter( $source->value, $this->getConfig() );
			if ( !is_null( $this->namespace ) ) {
				$importer->setTargetNamespace( $this->namespace );
			} elseif ( !is_null( $this->rootpage ) ) {
				$statusRootPage = $importer->setTargetRootPage( $this->rootpage );
				if ( !$statusRootPage->isGood() ) {
					$out->wrapWikiMsg(
						"<p class=\"error\">\n$1\n</p>",
						[
							'import-options-wrong',
							$statusRootPage->getWikiText(),
							count( $statusRootPage->getErrorsArray() )
						]
					);

					return;
				}
			}

			$out->addWikiMsg( "importstart" );

			$reporter = new ImportReporter(
				$importer,
				$isUpload,
				$this->fullInterwikiPrefix,
				$this->logcomment
			);
			$reporter->setContext( $this->getContext() );
			$exception = false;

			$reporter->open();
			try {
				$importer->doImport();
			} catch ( Exception $e ) {
				$exception = $e;
			}
			$result = $reporter->close();

			if ( $exception ) {
				# No source or XML parse error
				$out->wrapWikiMsg(
					"<p class=\"error\">\n$1\n</p>",
					[ 'importfailed', $exception->getMessage() ]
				);
			} elseif ( !$result->isGood() ) {
				# Zero revisions
				$out->wrapWikiMsg(
					"<p class=\"error\">\n$1\n</p>",
					[ 'importfailed', $result->getWikiText() ]
				);
			} else {
				# Success!
				$out->addWikiMsg( 'importsuccess' );
			}
			$out->addHTML( '<hr />' );
		}
	}

	private function getMappingFormPart( $sourceName ) {
		$isSameSourceAsBefore = ( $this->sourceName === $sourceName );
		$defaultNamespace = $this->getConfig()->get( 'ImportTargetNamespace' );
		return "<tr>
					<td>
					</td>
					<td class='mw-input'>" .
					Xml::radioLabel(
						$this->msg( 'import-mapping-default' )->text(),
						'mapping',
						'default',
						// mw-import-mapping-interwiki-default, mw-import-mapping-upload-default
						"mw-import-mapping-$sourceName-default",
						( $isSameSourceAsBefore ?
							( $this->mapping === 'default' ) :
							is_null( $defaultNamespace ) )
					) .
					"</td>
				</tr>
				<tr>
					<td>
					</td>
					<td class='mw-input'>" .
					Xml::radioLabel(
						$this->msg( 'import-mapping-namespace' )->text(),
						'mapping',
						'namespace',
						// mw-import-mapping-interwiki-namespace, mw-import-mapping-upload-namespace
						"mw-import-mapping-$sourceName-namespace",
						( $isSameSourceAsBefore ?
							( $this->mapping === 'namespace' ) :
							!is_null( $defaultNamespace ) )
					) . ' ' .
					Html::namespaceSelector(
						[
							'selected' => ( $isSameSourceAsBefore ?
								$this->namespace :
								( $defaultNamespace || '' ) ),
						], [
							'name' => "namespace",
							// mw-import-namespace-interwiki, mw-import-namespace-upload
							'id' => "mw-import-namespace-$sourceName",
							'class' => 'namespaceselector',
						]
					) .
					"</td>
				</tr>
				<tr>
					<td>
					</td>
					<td class='mw-input'>" .
					Xml::radioLabel(
						$this->msg( 'import-mapping-subpage' )->text(),
						'mapping',
						'subpage',
						// mw-import-mapping-interwiki-subpage, mw-import-mapping-upload-subpage
						"mw-import-mapping-$sourceName-subpage",
						( $isSameSourceAsBefore ? ( $this->mapping === 'subpage' ) : '' )
					) . ' ' .
					Xml::input( 'rootpage', 50,
						( $isSameSourceAsBefore ? $this->rootpage : '' ),
						[
							// Should be "mw-import-rootpage-...", but we keep this inaccurate
							// ID for legacy reasons
							// mw-interwiki-rootpage-interwiki, mw-interwiki-rootpage-upload
							'id' => "mw-interwiki-rootpage-$sourceName",
							'type' => 'text'
						]
					) . ' ' .
					"</td>
				</tr>";
	}

	private function showForm() {
		$action = $this->getPageTitle()->getLocalURL( [ 'action' => 'submit' ] );
		$user = $this->getUser();
		$out = $this->getOutput();
		$this->addHelpLink( '//meta.wikimedia.org/wiki/Special:MyLanguage/Help:Import', true );

		if ( $user->isAllowed( 'importupload' ) ) {
			$mappingSelection = $this->getMappingFormPart( 'upload' );
			$out->addHTML(
				Xml::fieldset( $this->msg( 'import-upload' )->text() ) .
					Xml::openElement(
						'form',
						[
							'enctype' => 'multipart/form-data',
							'method' => 'post',
							'action' => $action,
							'id' => 'mw-import-upload-form'
						]
					) .
					$this->msg( 'importtext' )->parseAsBlock() .
					Html::hidden( 'action', 'submit' ) .
					Html::hidden( 'source', 'upload' ) .
					Xml::openElement( 'table', [ 'id' => 'mw-import-table-upload' ] ) .
					"<tr>
					<td class='mw-label'>" .
					Xml::label( $this->msg( 'import-upload-filename' )->text(), 'xmlimport' ) .
					"</td>
					<td class='mw-input'>" .
					Html::input( 'xmlimport', '', 'file', [ 'id' => 'xmlimport' ] ) . ' ' .
					"</td>
				</tr>
				<tr>
					<td class='mw-label'>" .
					Xml::label( $this->msg( 'import-comment' )->text(), 'mw-import-comment' ) .
					"</td>
					<td class='mw-input'>" .
					Xml::input( 'log-comment', 50,
						( $this->sourceName === 'upload' ? $this->logcomment : '' ),
						[ 'id' => 'mw-import-comment', 'type' => 'text' ] ) . ' ' .
					"</td>
				</tr>
				$mappingSelection
				<tr>
					<td></td>
					<td class='mw-submit'>" .
					Xml::submitButton( $this->msg( 'uploadbtn' )->text() ) .
					"</td>
				</tr>" .
					Xml::closeElement( 'table' ) .
					Html::hidden( 'editToken', $user->getEditToken() ) .
					Xml::closeElement( 'form' ) .
					Xml::closeElement( 'fieldset' )
			);
		} else {
			if ( empty( $this->importSources ) ) {
				$out->addWikiMsg( 'importnosources' );
			}
		}

		if ( $user->isAllowed( 'import' ) && !empty( $this->importSources ) ) {
			# Show input field for import depth only if $wgExportMaxLinkDepth > 0
			$importDepth = '';
			if ( $this->getConfig()->get( 'ExportMaxLinkDepth' ) > 0 ) {
				$importDepth = "<tr>
							<td class='mw-label'>" .
					$this->msg( 'export-pagelinks' )->parse() .
					"</td>
							<td class='mw-input'>" .
					Xml::input( 'pagelink-depth', 3, 0 ) .
					"</td>
				</tr>";
			}
			$mappingSelection = $this->getMappingFormPart( 'interwiki' );

			$out->addHTML(
				Xml::fieldset( $this->msg( 'importinterwiki' )->text() ) .
					Xml::openElement(
						'form',
						[
							'method' => 'post',
							'action' => $action,
							'id' => 'mw-import-interwiki-form'
						]
					) .
					$this->msg( 'import-interwiki-text' )->parseAsBlock() .
					Html::hidden( 'action', 'submit' ) .
					Html::hidden( 'source', 'interwiki' ) .
					Html::hidden( 'editToken', $user->getEditToken() ) .
					Xml::openElement( 'table', [ 'id' => 'mw-import-table-interwiki' ] ) .
					"<tr>
					<td class='mw-label'>" .
					Xml::label( $this->msg( 'import-interwiki-sourcewiki' )->text(), 'interwiki' ) .
					"</td>
					<td class='mw-input'>" .
					Xml::openElement(
						'select',
						[ 'name' => 'interwiki', 'id' => 'interwiki' ]
					)
			);

			$needSubprojectField = false;
			foreach ( $this->importSources as $key => $value ) {
				if ( is_int( $key ) ) {
					$key = $value;
				} elseif ( $value !== $key ) {
					$needSubprojectField = true;
				}

				$attribs = [
					'value' => $key,
				];
				if ( is_array( $value ) ) {
					$attribs['data-subprojects'] = implode( ' ', $value );
				}
				if ( $this->interwiki === $key ) {
					$attribs['selected'] = 'selected';
				}
				$out->addHTML( Html::element( 'option', $attribs, $key ) );
			}

			$out->addHTML(
				Xml::closeElement( 'select' )
			);

			if ( $needSubprojectField ) {
				$out->addHTML(
					Xml::openElement(
						'select',
						[ 'name' => 'subproject', 'id' => 'subproject' ]
					)
				);

				$subprojectsToAdd = [];
				foreach ( $this->importSources as $key => $value ) {
					if ( is_array( $value ) ) {
						$subprojectsToAdd = array_merge( $subprojectsToAdd, $value );
					}
				}
				$subprojectsToAdd = array_unique( $subprojectsToAdd );
				sort( $subprojectsToAdd );
				foreach ( $subprojectsToAdd as $subproject ) {
					$out->addHTML( Xml::option( $subproject, $subproject, $this->subproject === $subproject ) );
				}

				$out->addHTML(
					Xml::closeElement( 'select' )
				);
			}

			$out->addHTML(
					"</td>
				</tr>
				<tr>
					<td class='mw-label'>" .
					Xml::label( $this->msg( 'import-interwiki-sourcepage' )->text(), 'frompage' ) .
					"</td>
					<td class='mw-input'>" .
					Xml::input( 'frompage', 50, $this->frompage, [ 'id' => 'frompage' ] ) .
					"</td>
				</tr>
				<tr>
					<td>
					</td>
					<td class='mw-input'>" .
					Xml::checkLabel(
						$this->msg( 'import-interwiki-history' )->text(),
						'interwikiHistory',
						'interwikiHistory',
						$this->history
					) .
					"</td>
				</tr>
				<tr>
					<td>
					</td>
					<td class='mw-input'>" .
					Xml::checkLabel(
						$this->msg( 'import-interwiki-templates' )->text(),
						'interwikiTemplates',
						'interwikiTemplates',
						$this->includeTemplates
					) .
					"</td>
				</tr>
				$importDepth
				<tr>
					<td class='mw-label'>" .
					Xml::label( $this->msg( 'import-comment' )->text(), 'mw-interwiki-comment' ) .
					"</td>
					<td class='mw-input'>" .
					Xml::input( 'log-comment', 50,
						( $this->sourceName === 'interwiki' ? $this->logcomment : '' ),
						[ 'id' => 'mw-interwiki-comment', 'type' => 'text' ] ) . ' ' .
					"</td>
				</tr>
				$mappingSelection
				<tr>
					<td>
					</td>
					<td class='mw-submit'>" .
					Xml::submitButton(
						$this->msg( 'import-interwiki-submit' )->text(),
						Linker::tooltipAndAccesskeyAttribs( 'import' )
					) .
					"</td>
				</tr>" .
					Xml::closeElement( 'table' ) .
					Xml::closeElement( 'form' ) .
					Xml::closeElement( 'fieldset' )
			);
		}
	}

	protected function getGroupName() {
		return 'pagetools';
	}
}

/**
 * Reporting callback
 * @ingroup SpecialPage
 */
class ImportReporter extends ContextSource {
	private $reason = false;
	private $logTags = [];
	private $mOriginalLogCallback = null;
	private $mOriginalPageOutCallback = null;
	private $mLogItemCount = 0;

	/**
	 * @param WikiImporter $importer
	 * @param bool $upload
	 * @param string $interwiki
	 * @param string|bool $reason
	 */
	function __construct( $importer, $upload, $interwiki, $reason = false ) {
		$this->mOriginalPageOutCallback =
			$importer->setPageOutCallback( [ $this, 'reportPage' ] );
		$this->mOriginalLogCallback =
			$importer->setLogItemCallback( [ $this, 'reportLogItem' ] );
		$importer->setNoticeCallback( [ $this, 'reportNotice' ] );
		$this->mPageCount = 0;
		$this->mIsUpload = $upload;
		$this->mInterwiki = $interwiki;
		$this->reason = $reason;
	}

	/**
	 * Sets change tags to apply to the import log entry and null revision.
	 *
	 * @param array $tags
	 * @since 1.29
	 */
	public function setChangeTags( array $tags ) {
		$this->logTags = $tags;
	}

	function open() {
		$this->getOutput()->addHTML( "<ul>\n" );
	}

	function reportNotice( $msg, array $params ) {
		$this->getOutput()->addHTML(
			Html::element( 'li', [], $this->msg( $msg, $params )->text() )
		);
	}

	function reportLogItem( /* ... */ ) {
		$this->mLogItemCount++;
		if ( is_callable( $this->mOriginalLogCallback ) ) {
			call_user_func_array( $this->mOriginalLogCallback, func_get_args() );
		}
	}

	/**
	 * @param Title $title
	 * @param ForeignTitle $foreignTitle
	 * @param int $revisionCount
	 * @param int $successCount
	 * @param array $pageInfo
	 * @return void
	 */
	public function reportPage( $title, $foreignTitle, $revisionCount,
			$successCount, $pageInfo ) {
		$args = func_get_args();
		call_user_func_array( $this->mOriginalPageOutCallback, $args );

		if ( $title === null ) {
			# Invalid or non-importable title; a notice is already displayed
			return;
		}

		$this->mPageCount++;
		$linkRenderer = MediaWikiServices::getInstance()->getLinkRenderer();
		if ( $successCount > 0 ) {
			// <bdi> prevents jumbling of the versions count
			// in RTL wikis in case the page title is LTR
			$this->getOutput()->addHTML(
				"<li>" . $linkRenderer->makeLink( $title ) . " " .
					"<bdi>" .
					$this->msg( 'import-revision-count' )->numParams( $successCount )->escaped() .
					"</bdi>" .
					"</li>\n"
			);

			$logParams = [ '4:number:count' => $successCount ];
			if ( $this->mIsUpload ) {
				$detail = $this->msg( 'import-logentry-upload-detail' )->numParams(
					$successCount )->inContentLanguage()->text();
				$action = 'upload';
			} else {
				$pageTitle = $foreignTitle->getFullText();
				$fullInterwikiPrefix = $this->mInterwiki;
				Hooks::run( 'ImportLogInterwikiLink', [ &$fullInterwikiPrefix, &$pageTitle ] );

				$interwikiTitleStr = $fullInterwikiPrefix . ':' . $pageTitle;
				$interwiki = '[[:' . $interwikiTitleStr . ']]';
				$detail = $this->msg( 'import-logentry-interwiki-detail' )->numParams(
					$successCount )->params( $interwiki )->inContentLanguage()->text();
				$action = 'interwiki';
				$logParams['5:title-link:interwiki'] = $interwikiTitleStr;
			}
			if ( $this->reason ) {
				$detail .= $this->msg( 'colon-separator' )->inContentLanguage()->text()
					. $this->reason;
			}

			$comment = $detail; // quick
			$dbw = wfGetDB( DB_MASTER );
			$latest = $title->getLatestRevID();
			$nullRevision = Revision::newNullRevision(
				$dbw,
				$title->getArticleID(),
				$comment,
				true,
				$this->getUser()
			);

			$nullRevId = null;
			if ( !is_null( $nullRevision ) ) {
				$nullRevId = $nullRevision->insertOn( $dbw );
				$page = WikiPage::factory( $title );
				# Update page record
				$page->updateRevisionOn( $dbw, $nullRevision );
				Hooks::run(
					'NewRevisionFromEditComplete',
					[ $page, $nullRevision, $latest, $this->getUser() ]
				);
			}

			// Create the import log entry
			$logEntry = new ManualLogEntry( 'import', $action );
			$logEntry->setTarget( $title );
			$logEntry->setComment( $this->reason );
			$logEntry->setPerformer( $this->getUser() );
			$logEntry->setParameters( $logParams );
			$logid = $logEntry->insert();
			if ( count( $this->logTags ) ) {
				$logEntry->setTags( $this->logTags );
			}
			// Make sure the null revision will be tagged as well
			$logEntry->setAssociatedRevId( $nullRevId );

			$logEntry->publish( $logid );

		} else {
			$this->getOutput()->addHTML( "<li>" . $linkRenderer->makeKnownLink( $title ) . " " .
				$this->msg( 'import-nonewrevisions' )->escaped() . "</li>\n" );
		}
	}

	function close() {
		$out = $this->getOutput();
		if ( $this->mLogItemCount > 0 ) {
			$msg = $this->msg( 'imported-log-entries' )->numParams( $this->mLogItemCount )->parse();
			$out->addHTML( Xml::tags( 'li', null, $msg ) );
		} elseif ( $this->mPageCount == 0 && $this->mLogItemCount == 0 ) {
			$out->addHTML( "</ul>\n" );

			return Status::newFatal( 'importnopages' );
		}
		$out->addHTML( "</ul>\n" );

		return Status::newGood( $this->mPageCount );
	}
}
