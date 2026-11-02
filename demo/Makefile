# Makefile for Storage Kit Bundle demos
DEMOS := symfony8

.PHONY: help up up-symfony8 down-symfony8 build-symfony8 install-symfony8 setup-symfony8 shell-symfony8
.PHONY: demo-smoke release-check release-verify verify-symfony8 clean

help:
	@echo "Storage Kit Bundle - Demos"
	@echo ""
	@echo "  make up / up-symfony8   Start Symfony 8 demo (http://localhost:8213)"
	@echo "  make down / down-symfony8  Stop Symfony 8 demo"
	@echo "  make update-bundle      Sync path-repo bundle into a demo (DEMO=symfony8)"
	@echo "  make build-symfony8     Rebuild Symfony 8 image"
	@echo "  make install-symfony8   Install deps"
	@echo "  make setup-symfony8     install + key + schema"
	@echo "  make shell-symfony8     Shell in PHP container"
	@echo "  make demo-smoke         REQ-TEST-011: boot demo + HTTP 200/302"
	@echo "  make release-check      Pre-release demo verification"
	@echo ""

up: up-symfony8

down: down-symfony8

update-bundle: update-bundle-symfony8

up-symfony8:
	$(MAKE) -C symfony8 up

down-symfony8:
	$(MAKE) -C symfony8 down

build-symfony8:
	$(MAKE) -C symfony8 build

install-symfony8:
	$(MAKE) -C symfony8 install

setup-symfony8:
	$(MAKE) -C symfony8 setup

shell-symfony8:
	$(MAKE) -C symfony8 shell

update-bundle-symfony8:
	$(MAKE) -C symfony8 update-bundle

verify-symfony8:
	$(MAKE) -C symfony8 up

# REQ-TEST-011: boot primary demo + one HTTP 200/302
demo-smoke:
	@PORT=$$(grep "^PORT=" symfony8/.env 2>/dev/null | cut -d= -f2 | tr -d '\r'); \
	[ -z "$$PORT" ] && PORT=$$(grep "^PORT=" symfony8/.env.example 2>/dev/null | cut -d= -f2 | tr -d '\r'); \
	[ -z "$$PORT" ] && PORT=8213; \
	echo "Demo smoke: symfony8 (http://127.0.0.1:$$PORT)"; \
	$(MAKE) -C symfony8 up || exit 1; \
	code=$$(curl -sL -o /dev/null -w "%{http_code}" "http://127.0.0.1:$$PORT/" || true); \
	[ -z "$$code" ] && code=000; \
	$(MAKE) -C symfony8 down 2>/dev/null || true; \
	if [ "$$code" != "200" ]; then echo "symfony8 failed with HTTP $$code"; exit 1; fi; \
	echo "symfony8 OK (HTTP $$code)"

release-verify:
	@for demo in $(DEMOS); do \
		port=$$(grep '^PORT=' $$demo/.env 2>/dev/null | cut -d= -f2 | tr -d '\r'); \
		[ -z "$$port" ] && port=$$(grep '^PORT=' $$demo/.env.example 2>/dev/null | cut -d= -f2 | tr -d '\r'); \
		[ -z "$$port" ] && port=8213; \
		echo "Release verify: $$demo (port $$port)..."; \
		$(MAKE) -C $$demo up || exit 1; \
		$(MAKE) -C $$demo update-bundle && $(MAKE) -C $$demo test || { $(MAKE) -C $$demo down 2>/dev/null; exit 1; }; \
		code=$$(curl -sL -o /dev/null -w "%{http_code}" http://127.0.0.1:$$port/ 2>/dev/null || echo "000"); \
		$(MAKE) -C $$demo down 2>/dev/null || true; \
		if [ "$$code" != "200" ]; then echo "Demo $$demo healthcheck failed (HTTP $$code)"; exit 1; fi; \
		echo "OK $$demo (HTTP $$code)"; \
	done
	@echo "All demos passed release-verify."

# REQ-DEMO-007: release-verify syncs the bundle (update-bundle) and runs the demo checks before the HTTP check
release-check: release-verify
	@echo "All demos passed release-check."

clean:
	@rm -rf $(addsuffix /vendor,$(DEMOS)) $(addsuffix /var,$(DEMOS)) $(addsuffix /.phpunit.cache,$(DEMOS))
	@echo "Cleaned demos."

# REQ-MAKE-008: update-deps-all
BUNDLE_ROOT := $(abspath $(dir $(lastword $(MAKEFILE_LIST)))/..)
-include $(BUNDLE_ROOT)/../.scripts/Makefile.demo-aggregate-update-deps.mk
