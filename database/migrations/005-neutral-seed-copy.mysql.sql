-- Compatibilidade: a versao 005 existia antes da migracao para perfis.
-- Novas instalacoes nao criam nem alteram entidades de produto.
UPDATE users SET updated_at = updated_at WHERE 1 = 0;
