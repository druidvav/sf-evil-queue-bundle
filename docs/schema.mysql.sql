create table xmlrpc_host_down
(
  host varchar(255) not null primary key,
  down_since timestamp null,
  down_untill timestamp null,
  fails int default '0' not null
);

create table xmlrpc_queue
(
  id int auto_increment comment 'Идентификатор запроса в очереди' primary key,
  name varchar(64) not null comment 'Название очереди',
  url varchar(255) not null comment 'URL ',
  method varchar(64) not null,
  request_param longtext null comment 'Параметры запроса',
  request_timeout int default '10' not null comment 'Таймаут запроса',
  tries int default '0' not null comment 'Количество попыток',
  create_date timestamp default CURRENT_TIMESTAMP not null comment 'Дата создания',
  last_request_start timestamp null,
  last_request_date timestamp null comment 'Дата последнего запроса',
  next_request_date datetime null,
  last_response longtext null comment 'Последний ответа',
  priority int default '0' not null,
  last_output longtext null comment 'Последний ответ в виде HTML',
  comment text null,
  protocol enum('xmlrpc', 'http', 'jsonrpc') default 'xmlrpc' not null comment 'Протокол запроса'
);
create index create_date on xmlrpc_queue (create_date);
create index last_request_date on xmlrpc_queue (last_request_date);
create index method on xmlrpc_queue (method);
create index name on xmlrpc_queue (name);
create index tries on xmlrpc_queue (tries);

create table xmlrpc_queue_complete
(
  id int auto_increment primary key,
  name varchar(64) not null comment 'Название очереди',
  url varchar(255) not null comment 'URL ',
  method varchar(64) not null,
  request_param longtext null comment 'Параметры запроса',
  request_timeout int default '10' not null comment 'Таймаут запроса',
  tries int default '0' not null comment 'Количество попыток',
  create_date timestamp default CURRENT_TIMESTAMP not null comment 'Дата создания',
  last_request_start timestamp null,
  last_request_date timestamp null comment 'Дата последнего запроса',
  last_response longtext null comment 'Последний ответа',
  priority int default '0' not null,
  last_output longtext null comment 'Последний ответ в виде HTML',
  comment text null,
  protocol enum('xmlrpc', 'http', 'jsonrpc') default 'xmlrpc' not null comment 'Протокол запроса',
  worker_code varchar(50) null
);
create index create_date on xmlrpc_queue_complete (create_date);
create index last_request_date on xmlrpc_queue_complete (last_request_date);
create index method on xmlrpc_queue_complete (method);
create index name on xmlrpc_queue_complete (name);
create index tries on xmlrpc_queue_complete (tries);
