# Configuration des couleurs
RED=\033[0;31m
GREEN=\033[0;32m
YELLOW=\033[0;33m
BLUE=\033[0;34m
CYAN=\033[0;36m
NO_COLOR=\033[0m

.DEFAULT_GOAL := help
.PHONY: help install rector rectorf lint lintf stan

help: ## Liste les commandes disponibles
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "$(CYAN)%-10s$(NO_COLOR) %s\n", $$1, $$2}'

install: ## Installe les dépendances (dont les outils de qualité)
	@echo "$(YELLOW)Installation des dépendances...$(NO_COLOR)"
	@composer install
	@echo "$(GREEN)Installation des dépendances terminée$(NO_COLOR)"

rector: ## Vérifie les transformations de Rector sans les appliquer
	@echo "$(YELLOW)Vérification des transformations de Rector...$(NO_COLOR)"
	@php ./vendor/bin/rector process --dry-run
	@echo "$(GREEN)Vérification des transformations de Rector terminée$(NO_COLOR)"

rectorf: ## Appliquer les transformations de Rector
	@echo "$(YELLOW)Application des transformations de Rector...$(NO_COLOR)"
	@php ./vendor/bin/rector process
	@echo "$(GREEN)Transformations de Rector appliquées$(NO_COLOR)"

lint: ## Lance php-cs-fixer en mode test
	@echo "$(YELLOW)Lancement de php-cs-fixer...$(NO_COLOR)"
	composer run lint
	@echo "$(GREEN)php-cs-fixer terminé$(NO_COLOR)"

lintf: ## Lance php-cs-fixer avec correction
	@echo "$(YELLOW)Lancement de php-cs-fixer avec correction...$(NO_COLOR)"
	composer run lint:fix
	@echo "$(GREEN)php-cs-fixer terminé$(NO_COLOR)"

stan: ## Lance PHPStan
	@echo "$(YELLOW)Lancement de PHPStan...$(NO_COLOR)"
	./vendor/bin/phpstan analyse -c phpstan.neon --memory-limit=3G
	@echo "$(GREEN)PHPStan terminé$(NO_COLOR)"
