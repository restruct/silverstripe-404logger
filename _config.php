<?php

Object::add_extension("RequestHandler", "FourOhFourLogger");
Object::add_extension("ContentController", "FourOhFourLogger");
Object::add_extension("ModelAsController", "FourOhFourLogger");