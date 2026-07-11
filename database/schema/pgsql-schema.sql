--
-- PostgreSQL database dump
--

\restrict 1lJZCzCPBPHmDOtQnepZscbiuSoPa0lvldHFnVlnC8FaPsY2mRd2ga1xBBy6BRW

-- Dumped from database version 18.4 (Debian 18.4-1.pgdg13+1)
-- Dumped by pg_dump version 18.4 (Debian 18.4-1.pgdg13+1)

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET transaction_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;

SET default_tablespace = '';

SET default_table_access_method = heap;

--
-- Name: categories; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.categories (
    id bigint NOT NULL,
    code character varying(10) NOT NULL,
    parent_code character varying(10),
    name character varying(255) NOT NULL,
    slug character varying(255) NOT NULL,
    type character varying(50),
    sort_order integer DEFAULT 0 NOT NULL,
    created_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


--
-- Name: categories_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.categories_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: categories_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.categories_id_seq OWNED BY public.categories.id;


--
-- Name: failed_jobs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.failed_jobs (
    id bigint NOT NULL,
    uuid character varying(255) NOT NULL,
    connection text NOT NULL,
    queue text NOT NULL,
    payload text NOT NULL,
    exception text NOT NULL,
    failed_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


--
-- Name: failed_jobs_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.failed_jobs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: failed_jobs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.failed_jobs_id_seq OWNED BY public.failed_jobs.id;


--
-- Name: migrations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.migrations (
    id integer NOT NULL,
    migration character varying(255) NOT NULL,
    batch integer NOT NULL
);


--
-- Name: migrations_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.migrations_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: migrations_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.migrations_id_seq OWNED BY public.migrations.id;


--
-- Name: password_reset_tokens; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.password_reset_tokens (
    email character varying(255) NOT NULL,
    token character varying(255) NOT NULL,
    created_at timestamp(0) without time zone
);


--
-- Name: sessions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.sessions (
    id character varying(255) NOT NULL,
    user_id bigint,
    ip_address character varying(45),
    user_agent text,
    payload text NOT NULL,
    last_activity integer NOT NULL
);


--
-- Name: spot_search_sync_queue; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.spot_search_sync_queue (
    spot_id bigint NOT NULL,
    token uuid NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL
);


--
-- Name: spots; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.spots (
    id bigint NOT NULL,
    message_id character varying(255) NOT NULL,
    poster character varying(255),
    poster_key_id character varying(64),
    title character varying(500) NOT NULL,
    description text,
    tag character varying(255),
    website character varying(500),
    category_code character varying(10) NOT NULL,
    subcategories jsonb DEFAULT '[]'::jsonb NOT NULL,
    file_size bigint DEFAULT '0'::bigint NOT NULL,
    nzb_segments jsonb DEFAULT '[]'::jsonb NOT NULL,
    spot_posted_at timestamp(0) without time zone NOT NULL,
    xml_signature character varying(255),
    is_verified boolean DEFAULT false NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    image_segments jsonb DEFAULT '[]'::jsonb NOT NULL
);


--
-- Name: spots_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.spots_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: spots_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.spots_id_seq OWNED BY public.spots.id;


--
-- Name: usenet_states; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.usenet_states (
    newsgroup character varying(255) NOT NULL,
    last_article_id bigint DEFAULT '0'::bigint NOT NULL,
    first_article_id bigint DEFAULT '0'::bigint NOT NULL,
    last_retrieval_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    last_backfilled_article_id bigint DEFAULT '0'::bigint NOT NULL
);


--
-- Name: user_downloads; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.user_downloads (
    id bigint NOT NULL,
    user_id bigint NOT NULL,
    spot_id bigint NOT NULL,
    downloaded_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


--
-- Name: user_downloads_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.user_downloads_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: user_downloads_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.user_downloads_id_seq OWNED BY public.user_downloads.id;


--
-- Name: user_filters; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.user_filters (
    id bigint NOT NULL,
    user_id bigint NOT NULL,
    name character varying(255) NOT NULL,
    filter_data jsonb NOT NULL,
    is_default boolean DEFAULT false NOT NULL,
    sort_order integer DEFAULT 0 NOT NULL,
    created_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


--
-- Name: user_filters_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.user_filters_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: user_filters_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.user_filters_id_seq OWNED BY public.user_filters.id;


--
-- Name: users; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.users (
    id bigint NOT NULL,
    username character varying(255) NOT NULL,
    name character varying(255) NOT NULL,
    email character varying(255) NOT NULL,
    email_verified_at timestamp(0) without time zone,
    password character varying(255) NOT NULL,
    is_admin boolean DEFAULT false NOT NULL,
    api_token character varying(32),
    last_login_at timestamp(0) without time zone,
    remember_token character varying(100),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    two_factor_secret text,
    two_factor_recovery_codes text,
    two_factor_confirmed_at timestamp(0) without time zone
);


--
-- Name: users_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.users_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: users_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.users_id_seq OWNED BY public.users.id;


--
-- Name: categories id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.categories ALTER COLUMN id SET DEFAULT nextval('public.categories_id_seq'::regclass);


--
-- Name: failed_jobs id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.failed_jobs ALTER COLUMN id SET DEFAULT nextval('public.failed_jobs_id_seq'::regclass);


--
-- Name: migrations id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.migrations ALTER COLUMN id SET DEFAULT nextval('public.migrations_id_seq'::regclass);


--
-- Name: spots id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.spots ALTER COLUMN id SET DEFAULT nextval('public.spots_id_seq'::regclass);


--
-- Name: user_downloads id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_downloads ALTER COLUMN id SET DEFAULT nextval('public.user_downloads_id_seq'::regclass);


--
-- Name: user_filters id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_filters ALTER COLUMN id SET DEFAULT nextval('public.user_filters_id_seq'::regclass);


--
-- Name: users id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users ALTER COLUMN id SET DEFAULT nextval('public.users_id_seq'::regclass);


--
-- Name: categories categories_code_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.categories
    ADD CONSTRAINT categories_code_unique UNIQUE (code);


--
-- Name: categories categories_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.categories
    ADD CONSTRAINT categories_pkey PRIMARY KEY (id);


--
-- Name: failed_jobs failed_jobs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.failed_jobs
    ADD CONSTRAINT failed_jobs_pkey PRIMARY KEY (id);


--
-- Name: failed_jobs failed_jobs_uuid_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.failed_jobs
    ADD CONSTRAINT failed_jobs_uuid_unique UNIQUE (uuid);


--
-- Name: migrations migrations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.migrations
    ADD CONSTRAINT migrations_pkey PRIMARY KEY (id);


--
-- Name: password_reset_tokens password_reset_tokens_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.password_reset_tokens
    ADD CONSTRAINT password_reset_tokens_pkey PRIMARY KEY (email);


--
-- Name: sessions sessions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sessions
    ADD CONSTRAINT sessions_pkey PRIMARY KEY (id);


--
-- Name: spot_search_sync_queue spot_search_sync_queue_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.spot_search_sync_queue
    ADD CONSTRAINT spot_search_sync_queue_pkey PRIMARY KEY (spot_id);


--
-- Name: spots spots_message_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.spots
    ADD CONSTRAINT spots_message_id_unique UNIQUE (message_id);


--
-- Name: spots spots_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.spots
    ADD CONSTRAINT spots_pkey PRIMARY KEY (id);


--
-- Name: usenet_states usenet_states_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.usenet_states
    ADD CONSTRAINT usenet_states_pkey PRIMARY KEY (newsgroup);


--
-- Name: user_downloads user_downloads_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_downloads
    ADD CONSTRAINT user_downloads_pkey PRIMARY KEY (id);


--
-- Name: user_downloads user_downloads_user_id_spot_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_downloads
    ADD CONSTRAINT user_downloads_user_id_spot_id_unique UNIQUE (user_id, spot_id);


--
-- Name: user_filters user_filters_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_filters
    ADD CONSTRAINT user_filters_pkey PRIMARY KEY (id);


--
-- Name: users users_api_token_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_api_token_unique UNIQUE (api_token);


--
-- Name: users users_email_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_email_unique UNIQUE (email);


--
-- Name: users users_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_pkey PRIMARY KEY (id);


--
-- Name: users users_username_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_username_unique UNIQUE (username);


--
-- Name: categories_parent_code_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX categories_parent_code_index ON public.categories USING btree (parent_code);


--
-- Name: idx_spots_fts; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_spots_fts ON public.spots USING gin (to_tsvector('english'::regconfig, (((title)::text || ' '::text) || COALESCE(description, ''::text))));


--
-- Name: idx_spots_fts_description; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_spots_fts_description ON public.spots USING gin (to_tsvector('english'::regconfig, COALESCE(description, ''::text)));


--
-- Name: idx_spots_fts_title_simple; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_spots_fts_title_simple ON public.spots USING gin (to_tsvector('simple'::regconfig, (title)::text));


--
-- Name: idx_spots_subcats; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_spots_subcats ON public.spots USING gin (subcategories);


--
-- Name: idx_spots_unenriched; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_spots_unenriched ON public.spots USING btree (id) WHERE (xml_signature IS NULL);


--
-- Name: sessions_last_activity_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX sessions_last_activity_index ON public.sessions USING btree (last_activity);


--
-- Name: sessions_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX sessions_user_id_index ON public.sessions USING btree (user_id);


--
-- Name: spot_search_sync_queue_updated_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX spot_search_sync_queue_updated_at_index ON public.spot_search_sync_queue USING btree (updated_at);


--
-- Name: spots_category_code_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX spots_category_code_index ON public.spots USING btree (category_code);


--
-- Name: spots_listing_cursor_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX spots_listing_cursor_index ON public.spots USING btree (spot_posted_at, id);


--
-- Name: spots_poster_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX spots_poster_index ON public.spots USING btree (poster);


--
-- Name: user_downloads user_downloads_spot_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_downloads
    ADD CONSTRAINT user_downloads_spot_id_foreign FOREIGN KEY (spot_id) REFERENCES public.spots(id) ON DELETE CASCADE;


--
-- Name: user_downloads user_downloads_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_downloads
    ADD CONSTRAINT user_downloads_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: user_filters user_filters_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_filters
    ADD CONSTRAINT user_filters_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- PostgreSQL database dump complete
--

\unrestrict 1lJZCzCPBPHmDOtQnepZscbiuSoPa0lvldHFnVlnC8FaPsY2mRd2ga1xBBy6BRW

--
-- PostgreSQL database dump
--

\restrict E8LQhD0ZqwuSthJgAj2mS67hJivqghvuPDRSdcd7FVJAm6Vr1kLh7XGUQ87sIYM

-- Dumped from database version 18.4 (Debian 18.4-1.pgdg13+1)
-- Dumped by pg_dump version 18.4 (Debian 18.4-1.pgdg13+1)

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET transaction_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;

--
-- Data for Name: migrations; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.migrations (id, migration, batch) FROM stdin;
1	0001_01_01_000000_create_users_table	1
2	0001_01_01_000001_create_cache_table	1
3	0001_01_01_000002_create_jobs_table	1
4	2026_02_18_113252_add_two_factor_columns_to_users_table	1
5	2026_02_18_113352_create_categories_table	1
6	2026_02_18_113352_create_settings_table	1
7	2026_02_18_113352_create_spots_table	1
8	2026_02_18_113352_create_user_downloads_table	1
9	2026_02_18_113352_create_user_filters_table	1
10	2026_02_18_113353_create_usenet_state_table	1
11	2026_02_18_142820_add_last_backfilled_article_id_to_usenet_states_table	1
12	2026_02_19_133126_add_simple_title_and_description_fts_indexes_to_spots	1
13	2026_02_19_164813_rename_api_key_to_api_token_on_users_table	1
14	2026_07_11_100546_add_unenriched_index_to_spots_table	1
15	2026_07_11_100546_create_spot_search_sync_queue_table	1
16	2026_07_11_100718_add_image_segments_to_spots_table	1
17	2026_07_11_102112_add_spot_listing_cursor_index_to_spots_table	1
18	2026_07_11_103822_drop_redundant_spot_posted_at_indexes_from_spots_table	1
19	2026_07_11_140656_drop_image_segment_from_spots_table	2
\.


--
-- Name: migrations_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.migrations_id_seq', 19, true);


--
-- PostgreSQL database dump complete
--

\unrestrict E8LQhD0ZqwuSthJgAj2mS67hJivqghvuPDRSdcd7FVJAm6Vr1kLh7XGUQ87sIYM

