"""Focused procedure gate checks in temporary repositories; no database access."""
import pathlib
import shutil
import subprocess
import tempfile
import unittest


class ProcedureGateTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix="agora-proc-gate-")
        self.root = pathlib.Path(self.temp.name)
        (self.root / "scripts").mkdir()
        source = pathlib.Path(__file__).resolve().parents[2] / "scripts/check-procs.sh"
        shutil.copy2(source, self.root / "scripts/check-procs.sh")
        self.procs = self.root / "Modules/Recon/Database/Procedures"
        self.procs.mkdir(parents=True)
        migrations = self.procs.parent / "Migrations"
        migrations.mkdir()
        (migrations / "v1__13p_recon_procs.php").write_text("<?php // extends ProcedureMigration\n")
        self.helper = self.procs / "usp_Recon_RunScope.sql"
        self.helper.write_text(self.parser("usp_Recon_RunScope"))
        self.subject = self.procs / "usp_Recon_GridRuns.sql"

    def tearDown(self):
        self.temp.cleanup()

    @staticmethod
    def parser(name):
        return (f"CREATE OR ALTER PROCEDURE [agora].[{name}] @FiltersJson nvarchar(max) AS\n"
                "SELECT JSON_VALUE(f.value, '$.column') FROM OPENJSON(@FiltersJson) f;\n")

    def run_gate(self, *paths):
        return subprocess.run(["bash", str(self.root / "scripts/check-procs.sh"), *paths],
                              text=True, capture_output=True, check=False)

    def target(self):
        return self.run_gate(str(self.subject.relative_to(self.root)))

    def delegation(self, body):
        self.subject.write_text("CREATE OR ALTER PROCEDURE [agora].[usp_Recon_GridRuns] "
                                "@FiltersJson nvarchar(max) AS\n" + body + "\n")

    def test_explicit_scope_does_not_scan_other_procedures_and_default_still_does(self):
        self.subject.write_text(self.parser("usp_Recon_GridRuns"))
        (self.procs / "broken.sql").write_text("SELECT 1;")
        scoped = self.target()
        self.assertEqual(0, scoped.returncode, scoped.stdout + scoped.stderr)
        self.assertIn("1 procedure(s) OK", scoped.stdout)
        whole = self.run_gate()
        self.assertNotEqual(0, whole.returncode)
        self.assertIn("broken.sql", whole.stdout)

    def test_exact_executable_delegation_accepts_real_helper_parser(self):
        for call in [
            "EXEC agora.usp_Recon_RunScope @FiltersJson=@FiltersJson;",
            "EXECUTE [agora].[usp_Recon_RunScope]\n @Search=@Search,\n @FiltersJson = @FiltersJson, @UserId=@UserId;",
        ]:
            with self.subTest(call=call):
                self.delegation(call)
                result = self.target()
                self.assertEqual(0, result.returncode, result.stdout + result.stderr)

    def test_changed_omitted_or_nonexecutable_filter_delegation_fails(self):
        for call in [
            "EXEC agora.usp_Recon_RunScope @FiltersJson=NULL;",
            "EXEC agora.usp_Recon_RunScope @FiltersJson=@OtherFilters;",
            "EXEC agora.usp_Recon_RunScope @Search=@Search;",
            "EXEC dbo.usp_Recon_RunScope @FiltersJson=@FiltersJson;",
            "EXEC agora.usp_Recon_RunScopeOther @FiltersJson=@FiltersJson;",
            "/* EXEC agora.usp_Recon_RunScope @FiltersJson=@FiltersJson; */ SELECT 1;",
            "SELECT 'EXEC agora.usp_Recon_RunScope @FiltersJson=@FiltersJson;';",
        ]:
            with self.subTest(call=call):
                self.delegation(call)
                result = self.target()
                self.assertNotEqual(0, result.returncode, call)
                self.assertIn("never reads $.column", result.stdout)

    def test_missing_or_broken_helper_cannot_justify_delegation(self):
        self.delegation("EXEC agora.usp_Recon_RunScope @FiltersJson=@FiltersJson;")
        for helper in [
            None,
            "CREATE OR ALTER PROCEDURE agora.usp_Recon_RunScope AS SELECT 1; /* $.column */",
            self.parser("usp_Recon_RunScope").replace("OPENJSON(@FiltersJson)", "OPENJSON(@OtherFilters)"),
            self.parser("usp_Recon_RunScope").replace("'$.column'", "'$.name'"),
            self.parser("usp_Recon_RunScope").replace("JSON_VALUE(f.value, '$.column')", "'$.column'"),
            self.parser("usp_Recon_RunScopeOther"),
            self.parser("usp_Recon_RunScope") + "SELECT * FROM OPENJSON(@FiltersJson) f WHERE f.[key] = 'Note';",
        ]:
            with self.subTest(helper=helper):
                if helper is None:
                    self.helper.unlink(missing_ok=True)
                else:
                    self.helper.write_text(helper)
                self.assertNotEqual(0, self.target().returncode)

    def test_missing_explicit_file_fails_instead_of_silently_passing(self):
        self.assertNotEqual(0, self.run_gate("Modules/Recon/Database/Procedures/missing.sql").returncode)


if __name__ == "__main__":
    unittest.main(verbosity=2)
