<?php

namespace MediaWiki\Linter;

use MediaWiki\DomainEvent\EventSubscriberBase;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Storage\PageUpdatedEvent;

class LintSubscriber extends EventSubscriberBase {
	private TotalsLookup $totalsLookup;
	private Database $database;

	public function __construct( TotalsLookup $totalsLookup, Database $database ) {
		$this->totalsLookup = $totalsLookup;
		$this->database = $database;
	}

	/**
	 * Remove entries from the linter table upon page content model change away from wikitext
	 *
	 * @noinspection PhpUnused
	 * @param PageUpdatedEvent $event
	 * @return void
	 */
	public function handlePageUpdatedEventAfterCommit( PageUpdatedEvent $event ) {
		$page = $event->getPage();
		$tags = $event->getTags();

		if (
			in_array( "mw-blank", $tags ) ||
			(
				in_array( "mw-contentmodelchange", $tags ) &&
				!in_array(
					$event->getNewRevision()->getSlot( SlotRecord::MAIN )->getModel(),
					Hooks::LINTABLE_CONTENT_MODELS
				)
			)
		) {
			$this->totalsLookup->updateStats(
				$this->database->setForPage( $page->getId(), $page->getNamespace(), [] )
			);
		}
	}
}
