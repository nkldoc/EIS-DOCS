package th.ac.nmu.eisdocs;

import org.junit.jupiter.api.Test;
import org.springframework.boot.test.context.SpringBootTest;

@SpringBootTest(properties = {
    "spring.autoconfigure.exclude=org.springframework.boot.autoconfigure.jdbc.DataSourceAutoConfiguration"
})
class EisDocsApplicationTests {
    @Test
    void contextLoads() {
    }
}

