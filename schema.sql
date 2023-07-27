create table artists
(
    id           varchar(255) not null,
    name         varchar(255) null,
    last_refresh datetime     null on update CURRENT_TIMESTAMP,
    constraint artists_id_uindex
        unique (id)
);

create table albums
(
    id     varchar(255) null,
    name   varchar(255) null,
    artist varchar(255) null,
    constraint albums_id_uindex
        unique (id),
    constraint albums_artists_id_fk
        foreign key (artist) references artists (id)
            on update cascade on delete cascade
);

create table tracks
(
    id    varchar(255) null,
    name  varchar(255) null,
    album varchar(255) null,
    constraint tracks_id_uindex
        unique (id),
    constraint tracks_albums_id_fk
        foreign key (album) references albums (id)
            on update cascade on delete cascade
);

