CREATE TABLE xmlrpc_host_down
(
  host TEXT PRIMARY KEY NOT NULL,
  down_since TIMESTAMP,
  down_untill TIMESTAMP,
  fails INTEGER DEFAULT 0 NOT NULL
);

CREATE TABLE xmlrpc_queue
(
  id BIGSERIAL PRIMARY KEY NOT NULL,
  name TEXT NOT NULL,
  url TEXT NOT NULL,
  method TEXT NOT NULL,
  request_param TEXT,
  request_timeout INTEGER DEFAULT 10 NOT NULL,
  tries INTEGER DEFAULT 0 NOT NULL,
  create_date TIMESTAMP DEFAULT now() NOT NULL,
  last_request_start TIMESTAMP,
  last_request_date TIMESTAMP,
  next_request_date TIMESTAMP,
  last_response TEXT,
  priority INTEGER DEFAULT 0 NOT NULL,
  last_output TEXT,
  comment TEXT,
  protocol TEXT DEFAULT 'xmlrpc'::text NOT NULL
);
CREATE INDEX name_idx ON xmlrpc_queue (name);
CREATE INDEX method_idx ON xmlrpc_queue (method);
CREATE INDEX tries_idx ON xmlrpc_queue (tries);
CREATE INDEX create_date_idx ON xmlrpc_queue (create_date);
CREATE INDEX last_request_date_idx ON xmlrpc_queue (last_request_date);

CREATE TABLE xmlrpc_queue_complete
(
  id BIGINT PRIMARY KEY NOT NULL,
  name TEXT NOT NULL,
  url TEXT NOT NULL,
  method TEXT NOT NULL,
  request_param TEXT,
  request_timeout INTEGER DEFAULT 10 NOT NULL,
  tries INTEGER DEFAULT 0 NOT NULL,
  create_date TIMESTAMP DEFAULT now() NOT NULL,
  last_request_start TIMESTAMP,
  last_request_date TIMESTAMP,
  last_response TEXT,
  priority INTEGER DEFAULT 0 NOT NULL,
  last_output TEXT,
  comment TEXT,
  protocol TEXT DEFAULT 'xmlrpc'::text NOT NULL,
  worker_code TEXT
);
CREATE INDEX name_idx ON xmlrpc_queue_complete (name);
CREATE INDEX method_idx ON xmlrpc_queue_complete (method);
CREATE INDEX tries_idx ON xmlrpc_queue_complete (tries);
CREATE INDEX create_date_idx ON xmlrpc_queue_complete (create_date);
CREATE INDEX last_request_date_idx ON xmlrpc_queue_complete (last_request_date);