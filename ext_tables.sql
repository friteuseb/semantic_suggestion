#
# root_page_id  = UID of the real site root page (Site::getRootPageId()).
#                 Shared by every analysis of the same site.
# scope_page_id = UID the scheduler task started from (its startPageId).
#                 Identifies one task run within a site, so that two tasks
#                 covering different subtrees of the same site do not erase
#                 each other's rows.
#
CREATE TABLE tx_semanticsuggestion_similarities (
    uid int(11) unsigned NOT NULL auto_increment,
    page_id int(11) unsigned DEFAULT '0' NOT NULL,
    similar_page_id int(11) unsigned DEFAULT '0' NOT NULL,
    similarity_score float DEFAULT '0' NOT NULL,
    root_page_id int(11) unsigned DEFAULT '0' NOT NULL,
    scope_page_id int(11) unsigned DEFAULT '0' NOT NULL,
    sys_language_uid int(11) DEFAULT '0' NOT NULL,
    crdate int(11) unsigned DEFAULT '0' NOT NULL,
    tstamp int(11) unsigned DEFAULT '0' NOT NULL,
    PRIMARY KEY (uid),
    KEY page_id (page_id),
    KEY root_page_id (root_page_id),
    KEY scope_page_id (scope_page_id),
    KEY frontend_lookup (page_id, root_page_id, sys_language_uid)
);
