package th.ac.nmu.eisdocs.notification;

import java.util.Set;
import java.util.concurrent.ConcurrentHashMap;
import java.util.concurrent.ConcurrentMap;

import org.springframework.stereotype.Component;
import org.springframework.web.socket.CloseStatus;
import org.springframework.web.socket.TextMessage;
import org.springframework.web.socket.WebSocketSession;
import org.springframework.web.socket.handler.TextWebSocketHandler;

@Component
public class NotificationSocketHandler extends TextWebSocketHandler {
    private final ConcurrentMap<String, Set<WebSocketSession>> sessionsByUser = new ConcurrentHashMap<>();
    private final WebSocketTokenService tokenService;

    public NotificationSocketHandler(WebSocketTokenService tokenService) { this.tokenService = tokenService; }

    @Override
    public void afterConnectionEstablished(WebSocketSession session) throws Exception {
        String userId = tokenService.userFrom(session.getUri() == null ? null : session.getUri().getQuery());
        if (userId == null) { session.close(CloseStatus.NOT_ACCEPTABLE.withReason("Invalid notification token")); return; }
        session.getAttributes().put("notificationUserId", userId);
        sessionsByUser.computeIfAbsent(userId, ignored -> ConcurrentHashMap.newKeySet()).add(session);
    }

    @Override
    public void afterConnectionClosed(WebSocketSession session, CloseStatus status) {
        Object user = session.getAttributes().get("notificationUserId");
        if (user == null) return;
        Set<WebSocketSession> sessions = sessionsByUser.get(user.toString());
        if (sessions != null) { sessions.remove(session); if (sessions.isEmpty()) sessionsByUser.remove(user.toString(), sessions); }
    }

    public void publish(String userId, String payload) {
        Set<WebSocketSession> sessions = sessionsByUser.get(userId);
        if (sessions == null) return;
        for (WebSocketSession session : sessions) {
            try { if (session.isOpen()) session.sendMessage(new TextMessage(payload)); }
            catch (Exception ignored) { }
        }
    }
}
