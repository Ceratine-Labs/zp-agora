#!/usr/bin/env bash
#
# The local SQL Server that Agora develops against.
#
# Agora owns its own database now, so development no longer writes to the
# customer's instance — it writes here. The customer's three databases stay
# remote and read-only.
#
# The host package cannot be used: mssql-server is installed on this machine but
# sqlservr links against liblber-2.5.so.0, and Ubuntu 24.04 ships OpenLDAP 2.6
# with no libldap-2.5-0 candidate in the archive. That is a Microsoft/Ubuntu
# version mismatch, not a configuration error, so the container is the supported
# route rather than a workaround.
#
#   scripts/local-sql.sh up       start the container and create both databases
#   scripts/local-sql.sh down     stop it (the volume survives)
#   scripts/local-sql.sh status   is it up, and what is in it
#   scripts/local-sql.sh destroy  stop it and delete the volume
#
set -uo pipefail
cd "$(dirname "$0")/.."

NAME="${AGORA_SQL_CONTAINER:-agora-sql}"
VOLUME="${NAME}-data"
IMAGE="mcr.microsoft.com/mssql/server:2022-latest"
PORT="$(grep -E '^AGORA_DB_PORT=' .env 2>/dev/null | cut -d= -f2 || echo 1433)"
PORT="${PORT:-1433}"

sa_password() {
    grep -E '^AGORA_DB_PASSWORD=' .env 2>/dev/null | cut -d= -f2-
}

sql() {
    docker exec -i "$NAME" /opt/mssql-tools18/bin/sqlcmd \
        -S localhost -U sa -P "$(sa_password)" -C -b "$@"
}

case "${1:-up}" in
up)
    PW="$(sa_password)"
    if [ -z "$PW" ]; then
        echo "AGORA_DB_PASSWORD is not set in .env. Put a strong password there first." >&2
        exit 1
    fi

    if [ -z "$(docker ps -aq -f name="^${NAME}$")" ]; then
        docker volume create "$VOLUME" >/dev/null
        docker run -d --name "$NAME" \
            -e ACCEPT_EULA=Y -e "MSSQL_SA_PASSWORD=$PW" -e MSSQL_PID=Developer \
            -p "${PORT}:1433" -v "${VOLUME}:/var/opt/mssql" \
            --restart unless-stopped "$IMAGE" >/dev/null
        echo "Created ${NAME} on port ${PORT}."
    else
        docker start "$NAME" >/dev/null
        echo "Started ${NAME}."
    fi

    printf 'Waiting for SQL Server'
    for _ in $(seq 1 60); do
        if sql -Q "SELECT 1" >/dev/null 2>&1; then echo " ready."; break; fi
        printf '.'; sleep 2
    done

    # Latin1_General_CI_AS to match PumpIT. A different collation makes every
    # cross-database string comparison throw "Cannot resolve collation
    # conflict" — agora.vw_* views read PumpIT across databases.
    sql -Q "
        IF DB_ID('Agora') IS NULL
        BEGIN
            CREATE DATABASE [Agora] COLLATE Latin1_General_CI_AS;
            ALTER DATABASE [Agora] SET RECOVERY SIMPLE;
        END
    " >/dev/null && echo "Database [Agora] ready."

    # A PumpIT stub, because the agora.vw_* views name it across databases and
    # three-part naming is same-instance only. It holds the shape, not the
    # estate: dbo.SS_Branch, filled from the real instance by
    # scripts/local-sql.sh seed-branches when you have a connection.
    sql -Q "
        IF DB_ID('PumpIT') IS NULL CREATE DATABASE [PumpIT] COLLATE Latin1_General_CI_AS;
    " >/dev/null
    sql -d PumpIT -Q "
        IF OBJECT_ID('dbo.SS_Branch') IS NULL
        CREATE TABLE dbo.SS_Branch (
            SSBranchId INT NOT NULL PRIMARY KEY,
            BranchName NVARCHAR(100) NOT NULL,
            BrandId INT NULL,
            RegionId INT NULL,
            ClassId INT NULL,
            IsActive BIT NOT NULL
        );
    " >/dev/null && echo "Stub [PumpIT].dbo.SS_Branch ready."

    # The recon estate: the ten tables the five agora.usp_Recon_Preview*
    # procedures read through agora.vw_*. Shape only — see the file's header.
    #
    # Order matters: pumpit-reports.sql widens SS_Branch and the two drop-safe
    # tables rather than redefining them, so both of the files above it must
    # have run first.
    for stub in database/stubs/pumpit-recon.sql database/stubs/pumpit-reports.sql; do
        docker exec -i "$NAME" /opt/mssql-tools18/bin/sqlcmd \
            -S localhost -U sa -P "$(sa_password)" -C -b -d PumpIT \
            -i /dev/stdin < "$stub" >/dev/null \
            && echo "Stub [PumpIT] $(basename "$stub" .sql | cut -d- -f2) tables ready."
    done

    echo
    echo "  php artisan agora:init-schema   # once, creates the agora schema"
    echo "  php artisan migrate"
    echo "  php artisan seed:master"
    ;;

seed-branches)
    # Copy the real branch rows into the stub, so a local BranchSeeder run and
    # the vw_Branch view see what production sees. A read of 31 rows.
    php artisan agora:sync-local-branches
    ;;

down)
    docker stop "$NAME" >/dev/null 2>&1 && echo "Stopped ${NAME}. The volume survives."
    ;;

status)
    docker ps -a --filter "name=^${NAME}$" --format 'container: {{.Names}}  {{.Status}}  {{.Ports}}'
    sql -h-1 -W -s'|' -Q "SET NOCOUNT ON; SELECT name, collation_name FROM sys.databases WHERE database_id > 4;" 2>/dev/null \
        || echo "not answering yet"
    ;;

destroy)
    docker rm -f "$NAME" >/dev/null 2>&1
    docker volume rm "$VOLUME" >/dev/null 2>&1
    echo "Removed ${NAME} and its volume. Everything local is gone."
    ;;

*)
    echo "usage: scripts/local-sql.sh {up|down|status|destroy|seed-branches}" >&2
    exit 1
    ;;
esac
