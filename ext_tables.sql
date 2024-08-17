CREATE TABLE tx_semanticsuggestion_nlp_results (
    uid int(11) NOT NULL auto_increment,
    pid int(11) DEFAULT '0' NOT NULL,
    page_uid int(11) DEFAULT '0' NOT NULL,
    sentiment varchar(255) DEFAULT '' NOT NULL,
    keyphrases text,
    category varchar(255) DEFAULT '' NOT NULL,
    named_entities text,
    word_count int(11) DEFAULT '0' NOT NULL,
    unique_word_count int(11) DEFAULT '0' NOT NULL,
    complexity float DEFAULT '0' NOT NULL,
    readability_score float DEFAULT '0' NOT NULL,
    tstamp int(11) unsigned DEFAULT '0' NOT NULL,
    crdate int(11) unsigned DEFAULT '0' NOT NULL,

    PRIMARY KEY (uid),
    KEY parent (pid),
    KEY page (page_uid)
);

CREATE TABLE tx_semanticsuggestion_nlp_task_progress (
    uid int(11) unsigned NOT NULL auto_increment,
    task_id int(11) unsigned NOT NULL,
    total_pages int(11) unsigned NOT NULL,
    processed_pages int(11) unsigned NOT NULL,
    status varchar(20) NOT NULL DEFAULT 'running',
    start_time int(11) unsigned NOT NULL,
    last_update int(11) unsigned NOT NULL,
    PRIMARY KEY (uid),
    KEY task_id (task_id)
);