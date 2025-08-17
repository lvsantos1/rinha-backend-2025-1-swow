# GLOBAL
WARM_CONTAINERS := lvsantos1-rinha-api1 lvsantos1-rinha-api2 lvsantos1-rinha-api3 lvsantos1-rinha-workers

# DEV

sockets:
	docker exec -it lvsantos1-rinha-api1 sh -c "chmod 0666 /http/*.sock"
api:
	docker exec -it -d lvsantos1-rinha-api1 sh -c 'kill $$(pidof php); php api.php' && \
	docker exec -it -d lvsantos1-rinha-api2 sh -c 'kill $$(pidof php); php api.php' && \
	docker exec -it -d lvsantos1-rinha-api3 sh -c 'kill $$(pidof php); php api.php' && \
	docker exec -it -d lvsantos1-rinha-workers sh -c 'kill $$(pidof php); php workers.php' $$ \
	sleep 1 && \
	docker exec -it lvsantos1-rinha-api1 sh -c "chmod 0666 /http/*.sock"
restart:
	docker compose down && docker compose up -d
restart-lb:
	docker compose down haproxy && docker compose up -d haproxy

# JIT

WARM_LOCAL_INI_SRC := ./php/warm-local.ini
WARM_LOCAL_INI_TGT := /usr/local/php-opt/lib/php.ini
RUN_LOCAL_INI_SRC := ./php/run-local.ini
RUN_LOCAL_INI_TGT := /usr/local/php-opt/lib/php.ini

%-clear-opcache:
	@docker exec $* sh -c "rm -R /tmp/.opcache/*"

clear-opcache-all: $(addsuffix -clear-opcache, $(WARM_CONTAINERS));

clear-opcache: clear-opcache-all

%-warm-local:
	@docker exec $* rm $(RUN_LOCAL_INI_TGT)
	@docker cp $(WARM_LOCAL_INI_SRC) $*:$(WARM_LOCAL_INI_TGT)

warm-local-all: $(addsuffix -warm-local, $(WARM_CONTAINERS));

warm-local: warm-local-all

%-run-local:
	@docker exec $* rm $(WARM_LOCAL_INI_TGT)
	@docker cp $(RUN_LOCAL_INI_SRC) $*:$(RUN_LOCAL_INI_TGT)

run-local-all: $(addsuffix -run-local, $(WARM_CONTAINERS))

run-local: run-local-all

# BUILD

WARM_INI_SRC := ./php/warm.ini
WARM_INI_TGT := /usr/local/php-opt/lib/php.ini
OPCACHE_DIR_SRC := /tmp/.opcache
OPCACHE_DIR_TGT := .opcache

%-pull-warm:
	@docker cp $*:$(OPCACHE_DIR_SRC) $(OPCACHE_DIR_TGT)

pull-warm-all: $(addsuffix -pull-warm, $(WARM_CONTAINERS))

pull-warm: pull-warm-all

%-warm:
	@docker cp $(WARM_INI_SRC) $*:$(WARM_INI_TGT)
	@docker exec $* rm $(RUN_LOCAL_INI_TGT)

warm-all: $(addsuffix -warm, $(WARM_CONTAINERS))

warm: warm-all