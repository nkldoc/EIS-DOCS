package th.ac.nmu.eisdocs;

import org.springframework.boot.SpringApplication;
import org.springframework.boot.autoconfigure.SpringBootApplication;
import org.springframework.boot.builder.SpringApplicationBuilder;
import org.springframework.boot.web.servlet.support.SpringBootServletInitializer;
import org.springframework.boot.web.servlet.ServletComponentScan;

@SpringBootApplication
@ServletComponentScan(basePackages = "com.eis.pdf")
public class EisDocsApplication extends SpringBootServletInitializer {
    public static void main(String[] args) {
        SpringApplication.run(EisDocsApplication.class, args);
    }

    @Override
    protected SpringApplicationBuilder configure(SpringApplicationBuilder application) {
        return application.sources(EisDocsApplication.class);
    }
}
