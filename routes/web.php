<?php

/*
 | Empty on purpose.
 |
 | Every route in Agora belongs to a module and is registered from
 | Modules/{Name}/Routes/*.php by App\Providers\ModuleServiceProvider, so the
 | prefix and middleware stack are identical everywhere and no route can be
 | published outside /app by accident.
 |
 | This file is loaded FIRST, before any module — so a route defined here would
 | silently win over a module's route for the same path. That is what happened
 | to '/' while the scaffolded welcome route was still sitting in it.
 */
