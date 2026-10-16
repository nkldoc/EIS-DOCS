package com.eis.pdf;

import com.google.gson.JsonObject;
import org.junit.jupiter.api.Test;
import static org.junit.jupiter.api.Assertions.*;

class SigningDatePolicyTest {
    @Test void omittedDateCannotBeAddedBySigningRequest() {
        JsonObject field = new JsonObject(); field.addProperty("signDateMode", "none");
        assertEquals("", PdfManagerServlet.resolveSigningDate(field, "7 October 2569"));
    }
    @Test void fixedDateOverridesActualSigningDate() {
        JsonObject field = new JsonObject(); field.addProperty("signDateMode", "fixed");
        field.addProperty("signDateFixed", "2026-01-01");
        assertEquals("1 มกราคม 2569", PdfManagerServlet.resolveSigningDate(field, "other date"));
    }
    @Test void cappedDateUsesDeadlineForLateSigning() {
        JsonObject field = new JsonObject(); field.addProperty("signDateMode", "capped");
        field.addProperty("signDateFixed", "2000-01-01");
        assertEquals("1 มกราคม 2543", PdfManagerServlet.resolveSigningDate(field, "other date"));
    }
    @Test void actualAndLegacyDatesRemainSupported() {
        JsonObject field = new JsonObject();
        assertEquals("actual", PdfManagerServlet.resolveSigningDate(field, "actual"));
        field.addProperty("signDateMode", "actual");
        assertEquals("actual", PdfManagerServlet.resolveSigningDate(field, "actual"));
    }
}
