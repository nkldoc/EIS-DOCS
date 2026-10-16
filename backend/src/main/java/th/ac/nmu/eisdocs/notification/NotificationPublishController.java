package th.ac.nmu.eisdocs.notification;

import java.util.Map;
import com.google.gson.Gson;
import org.springframework.beans.factory.annotation.Value;
import org.springframework.http.HttpStatus;
import org.springframework.web.bind.annotation.PostMapping;
import org.springframework.web.bind.annotation.RequestBody;
import org.springframework.web.bind.annotation.RequestHeader;
import org.springframework.web.bind.annotation.RequestMapping;
import org.springframework.web.bind.annotation.RestController;
import org.springframework.web.server.ResponseStatusException;

@RestController
@RequestMapping("/api/notifications")
public class NotificationPublishController {
    private final NotificationSocketHandler socketHandler;
    private final Gson gson = new Gson();
    @Value("${eis-docs.websocket-secret:}") private String secret;
    @Value("${spring.datasource.password:}") private String databasePassword;
    public NotificationPublishController(NotificationSocketHandler socketHandler) { this.socketHandler = socketHandler; }

    @PostMapping("/publish")
    public Map<String, Boolean> publish(@RequestHeader(value = "X-EIS-DOCS-Internal-Secret", defaultValue = "") String suppliedSecret, @RequestBody Map<String, Object> notification) {
        String requiredSecret = effectiveSecret();
        if (requiredSecret.isBlank() || !constantTime(suppliedSecret, requiredSecret)) throw new ResponseStatusException(HttpStatus.FORBIDDEN);
        Object user = notification.get("user_id");
        if (user == null || user.toString().isBlank()) throw new ResponseStatusException(HttpStatus.BAD_REQUEST);
        socketHandler.publish(user.toString(), gson.toJson(Map.of("type", "EIS_DOCS_NOTIFICATION", "notification", notification)));
        return Map.of("success", true);
    }
    private boolean constantTime(String left, String right) { if (left.length() != right.length()) return false; int value = 0; for (int i=0; i<left.length(); i++) value |= left.charAt(i) ^ right.charAt(i); return value == 0; }
    private String effectiveSecret() {
        if (secret != null && !secret.isBlank()) return secret;
        if (databasePassword == null || databasePassword.isBlank()) return "";
        try { byte[] bytes = java.security.MessageDigest.getInstance("SHA-256").digest((databasePassword + "|eis-docs-websocket-v1").getBytes(java.nio.charset.StandardCharsets.UTF_8)); StringBuilder output = new StringBuilder(); for (byte value : bytes) output.append(String.format("%02x", value)); return output.toString(); }
        catch (Exception ignored) { return ""; }
    }
}
